<?php

namespace Classivo\Timetable\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One period of an absent teacher on a date, and who covers it. */
class Substitution extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'timetable_substitutions';

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'date',
        'timetable_period_id',
        'absent_teacher_id',
        'substitute_teacher_id',
        'status',
        'reason',
        'note',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::class, 'timetable_period_id');
    }

    public function absentTeacher(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'absent_teacher_id');
    }

    public function substitute(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'substitute_teacher_id');
    }

    public function isAssigned(): bool
    {
        return $this->status === self::STATUS_ASSIGNED;
    }
}
