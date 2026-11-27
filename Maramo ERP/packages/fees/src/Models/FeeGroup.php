<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeGroup — a bundle of fee masters (e.g. "Class 1 Annual
 * Fees"). Tenant + branch + session scoped, soft-deleting.
 */
class FeeGroup extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'name',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function masters(): HasMany
    {
        return $this->hasMany(FeeMaster::class);
    }

    /** Sum of the amounts of the group's active fee masters. */
    public function getTotalAmountAttribute(): float
    {
        return (float) $this->masters()->sum('amount');
    }
}
