<?php

namespace Classivo\Core\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic Session / Year — level 3 of the tenancy model.
 */
class AcademicSession extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'academic_sessions';

    protected $fillable = [
        'school_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * Enforce "at most one current session per school" on EVERY save path
     * (form, table action, seeders, tinker), not just one UI action.
     */
    protected static function booted(): void
    {
        static::saved(function (self $session): void {
            if (! $session->is_current) {
                return;
            }

            static::withoutGlobalScope(TenantScope::class)
                ->where('school_id', $session->school_id)
                ->whereKeyNot($session->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false]);
        });

        static::forceDeleting(fn (self $session): ?bool => $session->holdsData() ? false : null);
    }

    /** True when the year still carries students or attendance. */
    public function holdsData(): bool
    {
        foreach (['students', 'attendances'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('session_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
