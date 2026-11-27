<?php

namespace Classivo\Students\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\ClassSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Daily student attendance — one row per (student, date). */
class Attendance extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'half_day' => 'Half Day',
        'holiday' => 'Holiday',
        'leave' => 'Leave',
    ];

    public const STATUS_COLORS = [
        'present' => 'success',
        'absent' => 'danger',
        'late' => 'warning',
        'half_day' => 'info',
        'holiday' => 'gray',
        'leave' => 'primary',
    ];

    public const STATUS_CHARS = [
        'present' => 'P',
        'absent' => 'A',
        'late' => 'L',
        'half_day' => '½',
        'holiday' => 'O',
        'leave' => 'V',
    ];

    protected $fillable = [
        'school_id', 'branch_id', 'session_id', 'student_id', 'class_section_id',
        'date', 'status', 'remark', 'marked_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return array<string, string> translated status labels */
    public static function statusOptions(): array
    {
        return collect(self::STATUSES)
            ->mapWithKeys(fn (string $label, string $key): array => [$key => __('classivo-students::students.attendance_statuses.'.$key)])
            ->all();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'marked_by');
    }
}
