<?php

namespace Classivo\Core\Tenancy\Scopes;

use Classivo\Core\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a model to the current school. Applies only once the request
 * context is resolved; in the console queries are left untouched. When no
 * school can be resolved the query matches nothing (never "the first school").
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentContext::class);

        if (! $context->isResolved()) {
            return;
        }

        $schoolId = $context->schoolIdOrNull();

        if ($schoolId === null) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->getTable().'.school_id', $schoolId);
    }
}
