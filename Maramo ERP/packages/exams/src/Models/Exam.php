<?php

namespace Classivo\Exams\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A concrete exam (e.g. "Mid Term") inside an exam group. It spans classes through
 * its schedules. `is_published` locks marks entry and marks the results as published;
 * the datesheet itself is visible as soon as schedules exist.
 */
class Exam extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id', 'branch_id', 'session_id', 'exam_group_id', 'class_id', 'name', 'description',
        'start_date', 'end_date', 'show_rank', 'weightage', 'is_published', 'result_published_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'show_rank' => 'boolean',
            'is_published' => 'boolean',
            'result_published_at' => 'datetime',
            'weightage' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function examGroup(): BelongsTo
    {
        return $this->belongsTo(ExamGroup::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    /** True when marks entry is locked (result already published). */
    public function isLocked(): bool
    {
        return (bool) $this->is_published;
    }
}
