<?php

namespace Classivo\Exams\Services;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\Subject;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Grade;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Support\MarksScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ExamMarksService — the single write path for the Marks Register.
 *
 * Resolves the exam-schedule for (exam, class-section, subject), validates each
 * student's obtained marks against the schedule's full marks, and upserts one
 * {@see Mark} row per student (keyed by exam + student + subject), stamping a
 * per-subject grade. Shared by the Marks Register page and the tests.
 *
 * Authorization (`exam.enter_marks`) is enforced by the caller (the page action).
 * This service enforces the teaching scope ({@see MarksScope}: a signed-in user may
 * only write marks for a class-section/subject they teach, unless they bypass it)
 * and the business rules: marks ≤ full marks, and no entry once the exam's result
 * is published (locked). Writes with no signed-in user (seeders, console) skip the
 * teaching-scope check.
 */
class ExamMarksService
{
    /**
     * Resolve the schedule that applies to a (class-section, subject) for an exam:
     * same class, matching subject, and either an all-sections row (section_id null)
     * or a row for this exact section. Null when none exists.
     */
    public function resolveSchedule(Exam $exam, ClassSection $classSection, Subject $subject): ?ExamSchedule
    {
        return ExamSchedule::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $classSection->class_id)
            ->where('subject_id', $subject->id)
            ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $classSection->section_id))
            ->orderByRaw('section_id IS NULL') // prefer a section-specific row over the all-sections row
            ->first();
    }

    /**
     * Save the marks register for one (exam, class-section, subject).
     *
     * @param  array<int, array<string, mixed>>  $rows  each: student_id,
     *                                                  marks_obtained, is_absent, remark
     * @return int number of student rows written
     *
     * @throws ValidationException when the exam is locked or any mark exceeds full marks
     * @throws AuthorizationException when the signed-in user does not teach the class-section/subject
     */
    public function save(Exam $exam, ClassSection $classSection, Subject $subject, array $rows, ?int $enteredBy = null): int
    {
        $actor = auth()->user();

        if ($actor && ! MarksScope::allows($actor, $classSection, $subject)) {
            throw new AuthorizationException(__('classivo-exams::ui.not_your_class_section'));
        }

        // A teaching-scoped user may only write rows for students enrolled in this class-section.
        if ($actor && ! MarksScope::bypasses($actor)) {
            $enrolled = app(ExamResultService::class)->studentsFor($classSection)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            foreach ($rows as $row) {
                if (! in_array((int) ($row['student_id'] ?? 0), $enrolled, true)) {
                    throw new AuthorizationException(__('classivo-exams::ui.not_your_class_section'));
                }
            }
        }

        if ($exam->isLocked()) {
            throw ValidationException::withMessages([
                'exam' => __('classivo-exams::exams.marks_locked'),
            ]);
        }

        $schedule = $this->resolveSchedule($exam, $classSection, $subject);

        if (! $schedule) {
            throw ValidationException::withMessages([
                'subject' => __('classivo-exams::exams.no_schedule'),
            ]);
        }

        $fullMarks = (float) $schedule->full_marks;
        $errors = [];

        foreach ($rows as $index => $row) {
            $absent = (bool) ($row['is_absent'] ?? false);
            $obtained = $row['marks_obtained'] ?? null;

            if ($absent || $obtained === null || $obtained === '') {
                continue;
            }

            if (! is_numeric($obtained)) {
                $errors["rows.{$index}.marks_obtained"] = __('classivo-exams::exams.not_numeric');
            } elseif ((float) $obtained > $fullMarks) {
                $errors["rows.{$index}.marks_obtained"] = __('classivo-exams::exams.over_full_marks', ['full' => $fullMarks]);
            } elseif ((float) $obtained < 0) {
                $errors["rows.{$index}.marks_obtained"] = __('classivo-exams::exams.negative');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $count = 0;

        DB::transaction(function () use ($rows, $exam, $classSection, $schedule, $subject, $enteredBy, $fullMarks, &$count): void {
            foreach ($rows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);

                if ($studentId <= 0) {
                    continue;
                }

                $absent = (bool) ($row['is_absent'] ?? false);
                $obtained = $absent ? null : ($row['marks_obtained'] ?? null);
                $obtained = ($obtained === '' ? null : $obtained);

                $gradeId = null;
                if (! $absent && $obtained !== null && $fullMarks > 0) {
                    $gradeId = Grade::forPercentage((float) $obtained / $fullMarks * 100)?->id;
                }

                Mark::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'student_id' => $studentId,
                        'subject_id' => $subject->id,
                    ],
                    [
                        'school_id' => $classSection->school_id,
                        'branch_id' => $classSection->branch_id,
                        'session_id' => $classSection->session_id,
                        'exam_schedule_id' => $schedule->id,
                        'marks_obtained' => $obtained,
                        'is_absent' => $absent,
                        'grade_id' => $gradeId,
                        'remark' => $row['remark'] ?? null,
                        'entered_by' => $enteredBy,
                    ],
                );

                $count++;
            }
        });

        return $count;
    }
}
