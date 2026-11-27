<?php

namespace Classivo\Core\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A subject allotted to a class (optionally one section), with marks and teacher. */
class ClassSubject extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'class_id',
        'section_id',
        'subject_id',
        'teacher_id',
        'is_optional',
        'full_marks',
        'pass_marks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
            'full_marks' => 'integer',
            'pass_marks' => 'integer',
            'sort_order' => 'integer',
        ];
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'teacher_id');
    }

    /**
     * Reconcile a class's (optionally a section's) subject allotment: listed
     * subjects are upserted (restored if trashed), the rest soft-deleted.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function allot(SchoolClass $class, ?int $sectionId, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $subjectId = (int) ($row['subject_id'] ?? 0);

            if ($subjectId <= 0) {
                continue;
            }

            $keep[] = $subjectId;

            $record = static::withTrashed()->firstOrNew([
                'class_id' => $class->getKey(),
                'section_id' => $sectionId,
                'subject_id' => $subjectId,
            ]);

            $record->fill([
                'teacher_id' => $row['teacher_id'] ?? null,
                'is_optional' => (bool) ($row['is_optional'] ?? false),
                'full_marks' => $row['full_marks'] ?? null,
                'pass_marks' => $row['pass_marks'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ]);
            $record->school_id = $class->school_id;
            $record->branch_id = $class->branch_id;
            $record->session_id = $class->session_id;
            $record->deleted_at = null;
            $record->save();
        }

        static::where('class_id', $class->getKey())
            ->where('section_id', $sectionId)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('subject_id', $keep))
            ->get()
            ->each->delete();
    }
}
