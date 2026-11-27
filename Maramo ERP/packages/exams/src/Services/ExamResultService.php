<?php

namespace Classivo\Exams\Services;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Exams\Events\ExamResultsGenerated;
use Classivo\Exams\Events\ExamResultsPublished;
use Classivo\Exams\Events\ExamResultsUnpublished;
use Classivo\Exams\Models\Division;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Grade;
use Classivo\Exams\Models\Mark;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ExamResultService — computes and publishes exam results .
 *
 * generate(): per class-section, sums each enrolled student's obtained marks over
 * the exam's applicable schedules, derives percentage, resolves grade + division
 * from the scale, decides pass/fail by subject pass-marks, ranks within the
 * class-section, and upserts one {@see ExamResult} per (exam, student). Regenerating
 * preserves the existing published flag.
 *
 * publish()/unpublish(): flip the exam + its results' published state. Authorization
 * (`exam.publish_result`) is enforced by the caller (the page action); this service
 * is the shared state-change used by the page and the tests.
 */
class ExamResultService
{
    /**
     * Generate results for one class-section of an exam.
     *
     * @return Collection<int, ExamResult>
     */
    public function generate(Exam $exam, ClassSection $classSection): Collection
    {
        // Schedules that apply to this class-section (all-sections rows + this section).
        $schedules = ExamSchedule::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $classSection->class_id)
            ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $classSection->section_id))
            ->get();

        $totalFull = (float) $schedules->sum(fn (ExamSchedule $s): float => (float) $s->full_marks);

        $students = $this->studentsFor($classSection);

        // Pass 1 — compute per-student totals + pass/fail.
        $rows = $students->map(function (Student $student) use ($exam, $schedules, $totalFull): array {
            $marks = Mark::query()
                ->where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->get()
                ->keyBy('subject_id');

            $total = 0.0;
            $passed = true;

            foreach ($schedules as $schedule) {
                $mark = $marks->get($schedule->subject_id);
                $obtained = ($mark && ! $mark->is_absent) ? (float) $mark->marks_obtained : 0.0;
                $total += $obtained;

                if (! $mark || $mark->is_absent || $obtained < (float) $schedule->pass_marks) {
                    $passed = false;
                }
            }

            $percentage = $totalFull > 0 ? round($total / $totalFull * 100, 2) : 0.0;

            return [
                'student' => $student,
                'total' => round($total, 2),
                'percentage' => $percentage,
                'passed' => $passed,
            ];
        })->values();

        // Rank within the class-section: highest total = rank 1, ties share a rank
        // (standard competition ranking: 1, 2, 2, 4).
        $ranked = $rows->sortByDesc('total')->values();
        $ranks = [];
        $lastTotal = null;
        $lastRank = 0;
        foreach ($ranked as $position => $row) {
            $rank = ($lastTotal !== null && $row['total'] === $lastTotal) ? $lastRank : $position + 1;
            $ranks[$row['student']->id] = $rank;
            $lastTotal = $row['total'];
            $lastRank = $rank;
        }

        // Pass 2 — persist.
        $results = DB::transaction(function () use ($rows, $ranks, $exam, $classSection, $totalFull): Collection {
            return $rows->map(function (array $row) use ($ranks, $exam, $classSection, $totalFull): ExamResult {
                $percentage = (float) $row['percentage'];

                $result = ExamResult::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'student_id' => $row['student']->id,
                    ],
                    [
                        'school_id' => $classSection->school_id,
                        'branch_id' => $classSection->branch_id,
                        'session_id' => $classSection->session_id,
                        'class_id' => $classSection->class_id,
                        'section_id' => $classSection->section_id,
                        'class_section_id' => $classSection->id,
                        'total_marks' => $row['total'],
                        'total_full_marks' => round($totalFull, 2),
                        'percentage' => $percentage,
                        'grade_id' => Grade::forPercentage($percentage)?->id,
                        'division_id' => Division::forPercentage($percentage)?->id,
                        'rank' => $ranks[$row['student']->id] ?? null,
                        'result' => $row['passed'] ? 'pass' : 'fail',
                    ],
                );

                return $result;
            });
        });

        ExamResultsGenerated::dispatch($exam, $classSection, $results);

        return $results;
    }

    /** Publish an exam's results (flips exam + result rows to published). */
    public function publish(Exam $exam): void
    {
        DB::transaction(function () use ($exam): void {
            $exam->forceFill([
                'is_published' => true,
                'result_published_at' => now(),
            ])->save();

            ExamResult::where('exam_id', $exam->id)->update([
                'published' => true,
                'published_at' => now(),
            ]);
        });

        ExamResultsPublished::dispatch($exam->refresh());
    }

    /** Unpublish an exam's results (hides them from student/parent views again). */
    public function unpublish(Exam $exam): void
    {
        DB::transaction(function () use ($exam): void {
            $exam->forceFill([
                'is_published' => false,
                'result_published_at' => null,
            ])->save();

            ExamResult::where('exam_id', $exam->id)->update([
                'published' => false,
                'published_at' => null,
            ]);
        });

        ExamResultsUnpublished::dispatch($exam->refresh());
    }

    /**
     * Active students enrolled in a class-section (resolved from their session record
     * for that class-section). Ordered by roll number.
     *
     * @return Collection<int, Student>
     */
    public function studentsFor(ClassSection $classSection): Collection
    {
        $records = StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('class_section_id', $classSection->id)
            ->orderByRaw('CAST(roll_no AS UNSIGNED)')
            ->orderBy('roll_no')
            ->get();

        $studentIds = $records->pluck('student_id')->all();

        if ($studentIds === []) {
            return collect();
        }

        $students = Student::query()
            ->whereIn('id', $studentIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        // Preserve roll-order from the session records.
        return collect($studentIds)
            ->map(fn (int $id): ?Student => $students->get($id))
            ->filter()
            ->values();
    }
}
