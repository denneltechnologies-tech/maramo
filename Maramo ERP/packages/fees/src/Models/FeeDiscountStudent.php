<?php

namespace Classivo\Fees\Models;

use Carbon\CarbonInterface;
use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Services\ConcessionService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Support\ConcessionEligibility;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Classivo\Fees\Support\Users;
use Classivo\Students\Models\Student;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeDiscountStudent — THE CONCESSION REGISTER.
 *
 * One row is one concession granted to one student: what it is worth, who granted
 * it, when, why, whether it recurs, whether it has been approved, and whether its
 * money has actually come off the bill yet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHERE THE MONEY MOVES — READ THIS BEFORE CHANGING ANYTHING BELOW
 * ─────────────────────────────────────────────────────────────────────────────
 * A concession's "ledger" is one column: `fee_student_allocations.discount_amount`.
 * Adding to it reduces `payable = amount − discount + fine` and therefore reduces
 * what the student owes. This model is the only thing that writes it on the
 * concession path, and it writes it in exactly two methods — {@see apply()} and
 * {@see reverse()} — with `applied_at` recording which side of that line the row
 * is currently on.
 *
 *   pending   → NOTHING is applied. The balance has not moved. This is a request.
 *   approved  → the concession is applied, once, and `applied_at` is stamped.
 *   rejected  → an applied concession is REVERSED and `applied_at` cleared.
 *
 * Three states, two transitions, and the rule that approving is what moves the
 * money. There is a single column to add to and subtract from, so no second
 * mechanism (observer, ledger) is involved.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * `approval_status` DEFAULTS TO 'approved', IN THE DATABASE AND IN $attributes
 * ─────────────────────────────────────────────────────────────────────────────
 * Every writer that predates this workflow — an admission flow that allocates fees and
 * the seeders — creates a row without setting the column, and must keep
 * behaving exactly as it does today: it calls `applyDiscount()` on the allocation
 * ITSELF and then records the grant here, so the money is already applied by the
 * time the row exists. Those rows are born approved, {@see apply()} is never called
 * on them, and {@see approve()} short-circuits on an already-approved row, so
 * nothing can double-apply. Gating a concession is opt-in, per row, by explicitly
 * creating it `pending` — which is what {@see ConcessionService} does
 * when {@see DiscountApprovalPolicy} says the grant is over the
 * school's limit.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE-TIME vs RECURRING
 * ─────────────────────────────────────────────────────────────────────────────
 *   • one_time  — the historical behaviour and the default. Names an allocation,
 *                 applies once, and is finished.
 *   • recurring — a STANDING INSTRUCTION: "this child holds a 50% staff-ward
 *                 concession". It names NO allocation and applies no money itself.
 *                 Each time a new allocation is raised for the student,
 *                 {@see ConcessionService::attachRecurringTo()}
 *                 spawns a one_time CHILD of it against that allocation. The
 *                 register therefore shows both the standing instruction and every
 *                 cycle it produced, which is what makes it auditable rather than
 *                 merely automatic.
 *
 * Two validity windows are enforced and the narrower wins: the catalogue entry's
 * ({@see FeeDiscount}) and this grant's own `valid_from`/`valid_to`, which exist so
 * a sibling concession can end when the elder child leaves without retiring the
 * catalogue entry for everyone.
 *
 * Tenant + branch scoped, soft-deleting.
 */
class FeeDiscountStudent extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Wire value → label, for the register's badge and the inbox detail sheet. */
    public const APPROVAL_STATUSES = [
        self::STATUS_PENDING => 'Pending approval',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    public const APPROVAL_STATUS_COLORS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_APPROVED => 'success',
        self::STATUS_REJECTED => 'danger',
    ];

    public const RECURRENCE_ONE_TIME = 'one_time';

    public const RECURRENCE_RECURRING = 'recurring';

    public const RECURRENCES = [
        self::RECURRENCE_ONE_TIME => 'One-time',
        self::RECURRENCE_RECURRING => 'Recurring (every new fee line)',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SIBLING = 'sibling';

    public const SOURCE_STAFF_WARD = 'staff_ward';

    public const SOURCE_ADMISSION = 'admission';

    public const SOURCE_RECURRING = 'recurring';

    /**
     * How a grant came about. `sibling` and `staff_ward` mean AN OPERATOR ACCEPTED
     * A SUGGESTION from {@see ConcessionEligibility} — nothing in
     * this system grants a concession because it inferred an entitlement.
     */
    public const SOURCES = [
        self::SOURCE_MANUAL => 'Granted manually',
        self::SOURCE_SIBLING => 'Sibling (suggested, confirmed by staff)',
        self::SOURCE_STAFF_WARD => 'Staff ward (suggested, confirmed by staff)',
        self::SOURCE_ADMISSION => 'Granted at admission',
        self::SOURCE_RECURRING => 'Raised by a recurring concession',
    ];

    protected $table = 'fee_discount_students';

    /**
     * MIRRORS THE DATABASE DEFAULTS, AND MUST KEEP DOING SO.
     *
     * A database default fills the ROW but never reaches the in-memory model, so
     * without this a `FeeDiscountStudent::create([...])` that omits the key holds a
     * NULL `approval_status` for the rest of the request. Every reader here treats
     * "not approved" as "do not touch the money", so the admission path — which
     * omits the key and relies on the row being approved — would silently start
     * behaving like a pending request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => self::STATUS_APPROVED,
        'recurrence' => self::RECURRENCE_ONE_TIME,
        'source' => self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'fee_discount_id',
        'student_id',
        'fee_student_allocation_id',
        'amount',
        'reason',
        'source',
        'recurrence',
        'parent_id',
        'valid_from',
        'valid_to',
        'assigned_by',
        'approval_status',
        'approved_by_id',
        'approved_at',
        'rejection_remark',
        'applied_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * Reverse an applied concession when its register row is deleted.
     *
     * Without this, deleting a grant from the register would leave its money
     * sitting in `fee_student_allocations.discount_amount` for ever — the student
     * would keep the concession and the record of it would be gone, which is the
     * worst of both. Soft deletes fire `deleting` too, so this covers the register's
     * ordinary Delete action as well as a force delete.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $grant): void {
            $grant->reverse();
        });

        // …and put it back on restore, or the register's Restore action would
        // return a row that says the student holds a concession while the bill
        // says they do not. Delete and restore have to be a round trip for the
        // money as well as for the row.
        static::restored(function (self $grant): void {
            $grant->apply();
        });
    }

    // ------------------------------------------------------------- relations

    /**
     * The catalogue entry, when there is one.
     *
     * `withTrashed()` for the same reason {@see FeeStudentAllocation::feeMaster()}
     * carries it: retiring a catalogue concession must not un-name the grants it
     * already produced, leaving the register with rows that show a blank concession.
     * NULL is legitimate here — a concession typed at the counter has no catalogue
     * row, which is precisely why `fee_discount_id` was made nullable.
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(FeeDiscount::class, 'fee_discount_id')->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FeeStudentAllocation::class, 'fee_student_allocation_id');
    }

    /** The user who granted it — NOT the user who approved it. */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'assigned_by');
    }

    /** The user who approved it — NOT the user who granted it. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'approved_by_id');
    }

    /** The standing instruction this per-cycle grant was raised from. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Every cycle this standing instruction has raised so far. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // ----------------------------------------------------------------- state

    public function isPendingApproval(): bool
    {
        return $this->approval_status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::STATUS_REJECTED;
    }

    public function isRecurring(): bool
    {
        return $this->recurrence === self::RECURRENCE_RECURRING;
    }

    /** Has this grant's money actually come off the student's bill? */
    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * Is this grant live on `$on` — approved, in date, and not withdrawn?
     *
     * BOTH windows are checked and the narrower wins: the catalogue entry's, and
     * this grant's own. A grant whose catalogue concession has expired is dead even
     * if the grant itself has no end date.
     */
    public function isActiveOn(?CarbonInterface $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        if (! $this->isApproved()) {
            return false;
        }

        if ($this->valid_from !== null && $on->lt($this->valid_from->copy()->startOfDay())) {
            return false;
        }

        if ($this->valid_to !== null && $on->gt($this->valid_to->copy()->startOfDay())) {
            return false;
        }

        // A grant with no catalogue entry (a counter concession recorded here) has
        // no second window to satisfy — its own is the whole of it.
        return $this->discount === null || $this->discount->isUsableOn($on);
    }

    // ---------------------------------------------------------------- scopes

    /** Awaiting a decision — an approvals screen queue. */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('approval_status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', self::STATUS_APPROVED);
    }

    /** Standing instructions only — the rows a new allocation must consult. */
    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('recurrence', self::RECURRENCE_RECURRING);
    }

    /**
     * Approved and inside its own validity window on `$on`.
     *
     * Only the GRANT's window is expressible in SQL here; the catalogue entry's is
     * applied afterwards through {@see isActiveOn()} rather than as a join, because
     * `discount()` carries `withTrashed()` and a join would quietly re-impose the
     * soft-delete scope and drop grants from retired catalogue entries.
     */
    public function scopeActiveOn(Builder $query, ?CarbonInterface $on = null): Builder
    {
        $date = ($on ?? now())->toDateString();

        return $query->approved()
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    // ----------------------------------------------------------- transitions

    /**
     * Approve — and APPLY THE CONCESSION to the allocation it names.
     *
     * Idempotent:
     * approving an already-approved grant returns immediately, so a legacy row (born
     * approved, money already applied by its creator) can never be applied a second
     * time, and neither can a double-tap on the register's Approve button.
     */
    public function approve(?User $actor = null): void
    {
        if ($this->isApproved()) {
            return;
        }

        $this->forceFill([
            'approval_status' => self::STATUS_APPROVED,
            'approved_by_id' => $actor?->getAuthIdentifier(),
            'approved_at' => now(),
            'rejection_remark' => null,
        ])->save();

        $this->apply();
    }

    /**
     * Reject, with a required reason — and REVERSE the concession if it was applied
     * (it will not have been for a pending grant; it will have been for one being
     * refused after the fact from the register).
     *
     * `approved_by_id` / `approved_at` are cleared rather than reused as
     * "decided by / decided at": they mean approval, and a rejection is not one.
     * The panel records who refused it in `rejection_remark`.
     */
    public function reject(?User $actor, string $remark): void
    {
        $this->reverse();

        $this->forceFill([
            'approval_status' => self::STATUS_REJECTED,
            'approved_by_id' => null,
            'approved_at' => null,
            'rejection_remark' => $remark,
        ])->save();
    }

    // ------------------------------------------------------------- the money

    /**
     * Add this grant's value to its allocation's `discount_amount` — the one write
     * that makes a concession real.
     *
     * Four refusals, each of which is a way this could otherwise double-count:
     *   • already applied (`applied_at` set) — the guard that makes approve() safe
     *     to call twice and makes legacy rows untouchable;
     *   • not approved — a pending or refused grant moves nothing, ever;
     *   • no allocation — a recurring standing instruction has no line of its own;
     *     its children carry the money;
     *   • nothing to apply — a zero grant writes no history.
     *
     * Returns the amount actually applied.
     */
    public function apply(): float
    {
        if ($this->isApplied() || ! $this->isApproved()) {
            return 0.0;
        }

        $amount = round((float) $this->amount, 2);
        $allocation = $this->allocation;

        if ($allocation === null || $amount <= 0) {
            return 0.0;
        }

        // CAP at what is still owed, for the same reason FeeCollectionService caps a
        // counter discount: a concession larger than the balance drives payable
        // (amount − discount + fine) negative, and the allocation then reads as the
        // school owing the student. Recomputed HERE and not trusted from the grant,
        // because an approval can land days after the request, by which time the
        // family may have paid.
        $amount = min($amount, $allocation->balance());

        if ($amount <= 0) {
            return 0.0;
        }

        $allocation->discount_amount = round((float) $allocation->discount_amount + $amount, 2);
        $allocation->syncStatus()->save();

        // `status` (assigned|used) is deliberately NOT touched. It predates this
        // workflow, the two historical writers disagree about what it means (the
        // admission service writes 'assigned', the sample seeder 'used') and nothing
        // reads it. `applied_at` is the honest signal for "the money has moved";
        // overloading a column whose meaning is already unclear would make the
        // register harder to read, not easier.
        $this->forceFill([
            'amount' => $amount,
            'applied_at' => now(),
        ])->save();

        return $amount;
    }

    /**
     * Take this grant's value back off the allocation — on rejection, or when the
     * register row is deleted.
     *
     * Floored at zero the same way {@see FeeCollectionService::revert()}
     * floors its own subtraction: if anything else has already reduced the figure,
     * subtracting again must not drive it negative.
     *
     * Returns the amount actually reversed.
     */
    public function reverse(): float
    {
        if (! $this->isApplied()) {
            return 0.0;
        }

        $amount = round((float) $this->amount, 2);
        $allocation = $this->allocation;

        if ($allocation !== null && $amount > 0) {
            $allocation->discount_amount = round(max(0, (float) $allocation->discount_amount - $amount), 2);
            $allocation->syncStatus()->save();
        }

        $this->forceFill(['applied_at' => null])->save();

        return $amount;
    }
}
