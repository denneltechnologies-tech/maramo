<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffDocument (spec §15 Staff "Documents" / §32 HR) — a titled document (file on the
 * public disk) attached to a staff member. Tenant + branch scoped, soft-deleting.
 *
 * EXPIRY: `expires_at` is nullable and NULL means "does not expire" (a degree
 * certificate), not "unknown". The expiring/expired reading below is the same one
 * transport uses for vehicle papers ({@see Vehicle::scopeDocumentsExpiringWithin()}):
 * a document counts as an ALERT when it expires on or before today + N days, and an
 * already-expired document (negative days) stays in the alert set rather than falling
 * out of it.
 */
class StaffDocument extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    /** Documents expiring inside this many days count as an alert. */
    public const ALERT_WINDOW_DAYS = 30;

    protected $fillable = [
        'school_id',
        'branch_id',
        'staff_id',
        'title',
        'file_path',
        'expires_at',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date:Y-m-d',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Whole days until this document expires — negative once it has. NULL when the
     * document carries no expiry at all, which is NOT the same as "0 days left".
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_at, false);
    }

    /** Already past its expiry date. */
    public function isExpired(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days < 0;
    }

    /** Expires within the alert window but has not expired yet. */
    public function isExpiringSoon(int $withinDays = self::ALERT_WINDOW_DAYS): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }

    /**
     * Scope: documents expiring within $days days — INCLUDING ones that already
     * expired, which is the reading {@see Vehicle::scopeDocumentsExpiringWithin()}
     * uses. A document that lapsed last month is the most urgent row on the screen,
     * not one that has stopped mattering.
     */
    public function scopeExpiringWithin(Builder $query, int $days = self::ALERT_WINDOW_DAYS): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', Clock::now()->addDays($days)->toDateString());
    }
}
