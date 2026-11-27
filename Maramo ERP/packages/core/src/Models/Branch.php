<?php

namespace Classivo\Core\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Support\Preset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch / Campus — level 2 of the tenancy model.
 */
class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'logo_path',
        'address',
        'city',
        'state',
        'country',
        'postcode',
        'email',
        'phone',
        'alt_phone',
        'website',
        'principal_name',
        'currency',
        'currency_symbol',
        'timezone',
        'language',
        'date_format',
        'weekend_days',
        'reg_no_prefix',
        'reg_no_start',
        'reg_no_digits',
        'session_start_month',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'weekend_days' => 'array',
            'reg_no_start' => 'integer',
            'reg_no_digits' => 'integer',
            'session_start_month' => 'integer',
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Fill regional defaults from the active preset when not given.
        static::creating(function (self $branch): void {
            foreach (['currency', 'currency_symbol', 'timezone', 'date_format', 'session_start_month', 'weekend_days'] as $key) {
                if ($branch->getAttribute($key) === null && Preset::get($key) !== null) {
                    $branch->setAttribute($key, Preset::get($key));
                }
            }

            if ($branch->getAttribute('language') === null) {
                $branch->setAttribute('language', Preset::get('locales', ['en'])[0] ?? 'en');
            }
        });

        // Never purge a campus that still holds academic history.
        static::forceDeleting(fn (self $branch): ?bool => $branch->hasProtectedHistory() ? false : null);
    }

    public function hasProtectedHistory(): bool
    {
        foreach (['students', 'attendances'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('branch_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
