<?php

namespace Classivo\Exams\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Distinction / First / Second / Third / Fail band defined by a minimum percentage. */
class Division extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = ['school_id', 'branch_id', 'session_id', 'name', 'min_percent'];

    protected function casts(): array
    {
        return ['min_percent' => 'decimal:2'];
    }

    /** The highest division whose min_percent <= the percentage. */
    public static function forPercentage(float $percentage): ?self
    {
        return static::query()
            ->where('min_percent', '<=', $percentage)
            ->orderByDesc('min_percent')
            ->first();
    }
}
