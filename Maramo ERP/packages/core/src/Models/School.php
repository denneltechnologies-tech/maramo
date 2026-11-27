<?php

namespace Classivo\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School / Institute — the top of the tenancy tree. Not tenant-scoped itself.
 */
class School extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'country',
        'postcode',
        'board',
        'logo_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Never purge a school that still holds academic history; deactivate it.
        static::forceDeleting(fn (self $school): ?bool => $school->hasProtectedHistory() ? false : null);
    }

    /** True when force-deleting would cascade-destroy students or attendance. */
    public function hasProtectedHistory(): bool
    {
        foreach (['students', 'attendances'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('school_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function academicSessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class);
    }
}
