<?php

namespace Classivo\Exams\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One exam × class × subject
 * sitting (optionally narrowed to a section), carrying date/time, room and the
 * full/pass marks used by the marks register and result generation. Tenant + branch
 * + session scoped, soft-deleting.
 */
class ExamSchedule extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'exam_id',
        'class_id',
        'section_id',
        'subject_id',
        'date',
        'start_time',
        'end_time',
        'room',
        'full_marks',
        'pass_marks',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'full_marks' => 'decimal:2',
            'pass_marks' => 'decimal:2',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function marksDistributions(): HasMany
    {
        return $this->hasMany(MarksDistribution::class);
    }

    /** "Mid Term · Grade 1 · Mathematics" label for selects/tables. */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' · ', array_filter([
            $this->exam?->name,
            $this->schoolClass?->name,
            $this->section?->name ? 'Sec '.$this->section->name : null,
            $this->subject?->name,
        ]))));
    }

    /**
     * Idempotently reconcile the schedule for an (exam, class, section) scope
     * (section null = every section of the class). Rows are created/updated keyed by
     * subject_id (restoring soft-deleted rows); subjects no longer present are
     * soft-deleted. Shared by the {@see BuildExamSchedule} page
     * and the test suite.
     *
     * @param  array<int, array<string, mixed>>  $rows  each: subject_id, date,
     *                                                  start_time, end_time, room,
     *                                                  full_marks, pass_marks
     */
    public static function buildSchedule(Exam $exam, int $classId, ?int $sectionId, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $subjectId = (int) ($row['subject_id'] ?? 0);

            if ($subjectId <= 0) {
                continue;
            }

            $keep[] = $subjectId;

            $record = static::withTrashed()->firstOrNew([
                'exam_id' => $exam->id,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'subject_id' => $subjectId,
            ]);

            $record->fill([
                'date' => $row['date'] ?? null,
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'room' => $row['room'] ?? null,
                'full_marks' => $row['full_marks'] ?? 100,
                'pass_marks' => $row['pass_marks'] ?? 35,
            ]);
            $record->school_id = $exam->school_id;
            $record->branch_id = $exam->branch_id;
            $record->session_id = $exam->session_id;
            $record->deleted_at = null;
            $record->save();
        }

        static::where('exam_id', $exam->id)
            ->where('class_id', $classId)
            ->where('section_id', $sectionId)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('subject_id', $keep))
            ->get()
            ->each->delete();
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }
}
