<?php

namespace Classivo\Timetable\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\ClassSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Timetable — one weekly routine per class-section per academic session; its
 * cells are {@see TimetablePeriod} rows. Tenant + branch + session scoped,
 * soft-deleting.
 */
class Timetable extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'class_section_id',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(TimetablePeriod::class)
            ->orderBy('day_of_week')
            ->orderBy('period_no')
            ->orderBy('start_time');
    }
}
