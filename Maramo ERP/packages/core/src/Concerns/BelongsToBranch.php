<?php

namespace Classivo\Core\Concerns;

use Classivo\Core\Models\Branch;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToBranch — level 2. Registers {@see BranchScope} and auto-fills
 * `branch_id` from the current branch. Combine with BelongsToTenant.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('branch_id')) && current_branch_id() !== null) {
                $model->setAttribute('branch_id', current_branch_id());
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
