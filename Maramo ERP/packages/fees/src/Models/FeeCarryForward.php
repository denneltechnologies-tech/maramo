<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Services\FeeCarryForwardService;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeCarryForward — the REGISTER of a student's
 * unpaid balance carried from a previous session into a later one. `session_id` is
 * the target (new) session; `from_session_id` the source. Tenant + branch + session
 * scoped, soft-deleting.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS ROW IS THE PROVENANCE. `fee_student_allocation_id` IS THE DEBT.
 * ─────────────────────────────────────────────────────────────────────────────
 * This table used to be write-only: the panel created rows and nothing read them
 * into a student's dues, so a carried balance could never be collected. It is now
 * one half of a pair — {@see FeeCarryForwardService} raises the
 * balance as a real {@see FeeStudentAllocation} (which every dues, collection,
 * receipt and report surface already understands) and links it here.
 *
 * So this row answers "where did this opening balance come from, who carried it,
 * and what did they say about it"; the allocation answers "how much is still
 * owed". `amount` stays the figure that was CARRIED — deliberately frozen, so the
 * register still reports what was brought forward after part of it has been paid.
 */
class FeeCarryForward extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    /**
     * Eager-loaded on every read because {@see status()} derives from it. Without
     * this, the admin carry-forward listing would fire one extra query per row to
     * answer a column it prints for every one of them; with it, a page of rows
     * costs a single `whereIn`.
     *
     * @var array<int, string>
     */
    protected $with = ['allocation'];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'from_session_id',
        'student_id',
        'fee_student_allocation_id',
        'amount',
        'note',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_session_id');
    }

    /**
     * The opening-balance allocation this row raised — the line the student
     * actually pays.
     *
     * Drops {@see SessionScope} for the same reason
     * {@see FeeStudentAllocation::feeMaster()} does: this is the resolution of a
     * foreign key the row already holds, not a listing. A register row is read
     * from whichever session the operator happens to be pinned to (a rollover is
     * driven from the OLD session as often as the new one), and under the pin the
     * allocation — which lives in the TARGET session — resolved to null, leaving
     * the row unable to say whether it had been collected. TenantScope and
     * BranchScope stay in place, so no other school's or campus's row is reachable.
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FeeStudentAllocation::class, 'fee_student_allocation_id')
            ->withTrashed()
            ->withoutGlobalScope(SessionScope::class);
    }

    /**
     * open | collected — DERIVED from the debt, not from what someone typed.
     *
     * The stored column is kept in step by
     * {@see FeeCarryForwardService::syncStatus()} (and its
     * reconcile/settlement paths, so `where('status', …)` filters stay correct),
     * but reading through the allocation means the value is right the instant the
     * receipt is written even if a settlement path was added that forgot to call
     * it. Rows predating the allocation link have nothing to derive from and fall
     * back to the stored value.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (mixed $value): string => $this->relationLoaded('allocation') || $this->fee_student_allocation_id !== null
            ? $this->derivedStatus()
            : (string) $value);
    }

    /**
     * What the status SHOULD be, computed from the allocation's outstanding
     * balance. Falls back to the stored column when there is no linked debt.
     */
    public function derivedStatus(): string
    {
        $allocation = $this->allocation;

        if (! $allocation instanceof FeeStudentAllocation) {
            return (string) ($this->getRawOriginal('status') ?: 'open');
        }

        return $allocation->balance() <= 0.001 ? 'collected' : 'open';
    }

    /** How much of the carried balance is still owed (0 once settled). */
    public function outstanding(): float
    {
        return $this->allocation instanceof FeeStudentAllocation
            ? $this->allocation->balance()
            : (float) $this->amount;
    }

    /** True once the money has actually arrived. */
    public function isCollected(): bool
    {
        return $this->derivedStatus() === 'collected';
    }
}
