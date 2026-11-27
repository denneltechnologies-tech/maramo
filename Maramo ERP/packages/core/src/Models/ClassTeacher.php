<?php

namespace Classivo\Core\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A teacher assigned to a class-section (one may be primary). */
class ClassTeacher extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'class_section_id',
        'teacher_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'teacher_id');
    }

    /**
     * Reconcile the teachers of a class-section.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function assign(ClassSection $classSection, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $teacherId = (int) ($row['teacher_id'] ?? 0);

            if ($teacherId <= 0) {
                continue;
            }

            $keep[] = $teacherId;

            $record = static::withTrashed()->firstOrNew([
                'class_section_id' => $classSection->getKey(),
                'teacher_id' => $teacherId,
            ]);

            $record->is_primary = (bool) ($row['is_primary'] ?? false);
            $record->school_id = $classSection->school_id;
            $record->branch_id = $classSection->branch_id;
            $record->session_id = $classSection->session_id;
            $record->deleted_at = null;
            $record->save();
        }

        static::where('class_section_id', $classSection->getKey())
            ->when($keep !== [], fn ($query) => $query->whereNotIn('teacher_id', $keep))
            ->get()
            ->each->delete();
    }
}
