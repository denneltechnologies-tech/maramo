<?php

namespace Classivo\Core\Tenancy\Scopes;

use Classivo\Core\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Constrains a model to the current branch (when resolved and selected). */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentContext::class);

        if (! $context->isResolved() || ($branchId = $context->branchId()) === null) {
            return;
        }

        $builder->where($model->getTable().'.branch_id', $branchId);
    }
}
