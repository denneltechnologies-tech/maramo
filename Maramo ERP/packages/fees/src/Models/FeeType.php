<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeType — a named charge head (Tuition, Admission, Exam …).
 * Tenant + branch scoped master, soft-deleting. `is_system` marks
 * the transport/hostel auto heads that other modules attach automatically.
 */
class FeeType extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'code',
        'description',
        'is_system',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function masters(): HasMany
    {
        return $this->hasMany(FeeMaster::class);
    }
}
