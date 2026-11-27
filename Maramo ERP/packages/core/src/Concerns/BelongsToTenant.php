<?php

namespace Classivo\Core\Concerns;

use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * BelongsToTenant — level 1. Apply to any table carrying `school_id`:
 * registers {@see TenantScope} and auto-fills `school_id` on create.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (! empty($model->getAttribute('school_id'))) {
                return;
            }

            $schoolId = current_context()->schoolIdOrNull();

            if ($schoolId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create [%s]: no school could be resolved. Set school_id explicitly.',
                    static::class,
                ));
            }

            $model->setAttribute('school_id', $schoolId);
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
