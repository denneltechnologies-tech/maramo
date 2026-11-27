<?php

namespace Classivo\Exams\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A grading-scale band: name, percentage range, grade point and a fail flag. */
class Grade extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = ['school_id', 'branch_id', 'session_id', 'name', 'min_percent', 'max_percent', 'grade_point', 'remark', 'is_fail'];

    protected function casts(): array
    {
        return [
            'min_percent' => 'decimal:2',
            'max_percent' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'is_fail' => 'boolean',
        ];
    }

    /** The band whose [min_percent, max_percent] contains the percentage. */
    public static function forPercentage(float $percentage): ?self
    {
        return static::query()
            ->where('min_percent', '<=', $percentage)
            ->where('max_percent', '>=', $percentage)
            ->orderByDesc('min_percent')
            ->first();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('min_percent');
    }
}
