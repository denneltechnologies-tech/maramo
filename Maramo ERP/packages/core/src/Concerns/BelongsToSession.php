<?php

namespace Classivo\Core\Concerns;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToSession — level 3. Registers {@see SessionScope} and auto-fills
 * `session_id` from the active academic session.
 */
trait BelongsToSession
{
    public static function bootBelongsToSession(): void
    {
        static::addGlobalScope(new SessionScope);

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('session_id')) && current_session_id() !== null) {
                $model->setAttribute('session_id', current_session_id());
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }
}
