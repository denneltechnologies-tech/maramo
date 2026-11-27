<?php

namespace Classivo\Fees\Models;

use Carbon\CarbonInterface;
use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Observers\FeeStudentAllocationObserver;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeStudentAllocation — one fee master
 * assigned to one student for one session, carrying the running balance:
 *   payable = amount − discount_amount + fine_amount
 *   balance = payable − paid_amount
 * Status is derived from the balance (unpaid / partial / paid). Tenant + branch +
 * session scoped, soft-deleting. Money = decimal(15,2).
 */
#[ObservedBy(FeeStudentAllocationObserver::class)]
class FeeStudentAllocation extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'unpaid' => 'Unpaid',
        'partial' => 'Partly Paid',
        'paid' => 'Paid',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'student_id',
        'fee_master_id',
        'amount',
        'discount_amount',
        'fine_amount',
        'paid_amount',
        'due_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
            // The fine rule this debt was ISSUED under. Editing a master's fine
            // rule must not retroactively re-price dues already raised, so the
            // rule is frozen here and computed from. A null snapshot means
            // "issued before rules were frozen" and falls back to the master.
            'fine_rule_amount' => 'decimal:2',
            'fine_grace_days' => 'integer',
            'fine_accrued_at' => 'datetime',
            'fine_waived' => 'boolean',
            // The reminder ladder's ledger, named as on `subscriptions`:
            // reminder_stage is the rung last announced (3|7|15|30),
            // last_reminded_at is when.
            'reminder_stage' => 'integer',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The priced fee line this allocation was created from.
     *
     * DELIBERATELY bypasses two of FeeMaster's global scopes. An allocation is a
     * DEBT THE STUDENT OWES; `fee_master_id` is a foreign key it already holds.
     * Those scopes exist to filter LISTINGS ("which masters may I pick from
     * now?"), and applying them to the resolution of an FK that is already set
     * does not hide a row — it severs the row's own name, leaving a payable
     * line with `head: null`:
     *
     *  • SoftDeletingScope — retiring a price-list template must not un-name a
     *    debt already assigned from it; otherwise an open line loses its head
     *    and the student cannot pay it.
     *  • SessionScope — an allocation legitimately outlives the session pin.
     *    FeeStatementService reads allocations across sessions on purpose
     *    (`withoutGlobalScope(SessionScope::class)`) for past-session
     *    statements, but the master then still lives in ITS session, so under
     *    the pin it resolved to null and every historical line lost its head.
     *
     * SAFETY — TenantScope and BranchScope are LEFT IN PLACE, so a master from
     * another school or another campus still resolves to null. The only
     * relaxation is within one school + branch, along a foreign key the caller
     * already possesses; this is a belongsTo resolution, never a listing, so no
     * row becomes reachable that the allocation did not already point at.
     */
    public function feeMaster(): BelongsTo
    {
        return $this->belongsTo(FeeMaster::class)
            ->withTrashed()
            ->withoutGlobalScope(SessionScope::class);
    }

    public function paymentDetails(): HasMany
    {
        return $this->hasMany(FeePaymentDetail::class);
    }

    /** amount − discount + fine (the net amount owed before payments). */
    public function payable(): float
    {
        return round((float) $this->amount - (float) $this->discount_amount + (float) $this->fine_amount, 2);
    }

    /** Outstanding balance = payable − paid (never below 0). */
    public function balance(): float
    {
        return round(max(0, $this->payable() - (float) $this->paid_amount), 2);
    }

    /** True when this allocation is past its due date and still has a balance. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->balance() > 0;
    }

    /** Whole days this allocation is overdue as of `$asOf` (0 when not overdue). */
    public function daysOverdue(?CarbonInterface $asOf = null): int
    {
        if (! $this->due_date) {
            return 0;
        }

        $asOf ??= now();
        $days = (int) $this->due_date->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);

        return max(0, $days);
    }

    /**
     * Recompute the derived status from the current paid/payable figures.
     *
     * THE SETTLED ARM COMES FIRST, and the order is the whole fix. The test used
     * to be `paid <= 0 => 'unpaid'` before anything else, so a line with a 100%
     * concession — payable 0, balance 0, nothing whatever owed — kept the status
     * `unpaid` for ever, because no money had been received and none ever would
     * be. It was never a false defaulter (the defaulter query intersects with a
     * balance condition), but this enum is printed straight onto the Collect Fees
     * sheet, the 360° Fees tab and the fee statement, where it reads to a parent
     * as a debt that does not exist.
     *
     * "Nothing is owed" is the settled state whatever the route to it: paid in
     * full, waived in full, or a zero-value line. Only a line that still has
     * something to collect can be `unpaid`.
     */
    public function syncStatus(): static
    {
        $paid = (float) $this->paid_amount;
        $payable = $this->payable();

        $this->status = match (true) {
            $payable <= 0.001 => 'paid',
            $paid <= 0 => 'unpaid',
            $paid + 0.001 >= $payable => 'paid',
            default => 'partial',
        };

        return $this;
    }

    /**
     * Apply a discount to this allocation (gated `fees.discount` at the call site):
     * adds the computed concession to discount_amount and re-syncs status. Returns the
     * money value applied.
     */
    public function applyDiscount(FeeDiscount $discount): float
    {
        $applied = $discount->computeFor((float) $this->amount);

        $this->discount_amount = round((float) $this->discount_amount + $applied, 2);
        $this->syncStatus()->save();

        return $applied;
    }
}
