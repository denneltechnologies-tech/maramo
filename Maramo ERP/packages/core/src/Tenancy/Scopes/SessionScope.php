<?php

namespace Classivo\Core\Tenancy\Scopes;

use Classivo\Core\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Constrains a model to the current academic session (when resolved and selected). */
class SessionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentContext::class);

        if (! $context->isResolved() || ($sessionId = $context->sessionId()) === null) {
            return;
        }

        $builder->where($model->getTable().'.session_id', $sessionId);
    }
}
