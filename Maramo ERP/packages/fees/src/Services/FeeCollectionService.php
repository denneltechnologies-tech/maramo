<?php

namespace Classivo\Fees\Services;

use Classivo\Core\Models\Branch;
use Classivo\Fees\Contracts\CollectionGuard;
use Classivo\Fees\Events\FeePaymentRecorded;
use Classivo\Fees\Events\FeePaymentReverted;
use Classivo\Fees\Exceptions\NothingToCollectException;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeePaymentDetail;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Students\Models\Student;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FeeCollectionService — the money-movement core of the Fees module.
 *
 * collect(): in one DB transaction, mints a sequential per-branch receipt number
 * (invoice_prefix + series), writes the {@see FeePayment} + its {@see FeePaymentDetail}
 * lines, advances each targeted allocation's discount/fine/paid figures (partial
 * payments supported) and fires {@see FeePaymentRecorded} for any accounting listener.
 * It refuses outright to mint anything for an empty basket
 * ({@see NothingToCollectException}) — see {@see nextReceiptSeq()} for why a
 * receipt number can never be issued speculatively and reclaimed afterwards.
 * A line's `fine` is charged to the allocation AND collected by the same receipt:
 * the money received on a line is `amount + fine`, so `paid_amount` (receipt and
 * allocation) advances by both — a full pay therefore settles the fine too instead
 * of leaving it behind as a fresh balance.
 *
 * A line's `discount` is the opposite: money the school never receives. Two rules
 * live here rather than in the callers, because every posting path funnels through
 * collect(): it is CAPPED at the line's outstanding balance (so payable can never go
 * negative), and it is ATTRIBUTED — the granting user and the reason are written onto
 * the {@see FeePaymentDetail}. WHO may grant one at all is
 * still the caller's decision (`fees.discount`), for the same reason `fees.collect` is:
 * the webhook and approval paths have no authenticated user to ask.
 *
 * A line's `fine` now carries THE SAME TWO RULES, and for the same money reason.
 * ------------------------------------------------------------------------------
 * A fine is not a suggestion the till may improvise: it adds to what the family
 * pays AND posts a credit to the general ledger, so an invented one is cash in the
 * day book the school never received. Until this change `fine` was whatever the
 * caller sent — a user holding only `fees.collect` could post `fine: 999999` on a
 * ₹3,000 line and get a receipt, a ₹999,999 ledger credit and a cash-book balance
 * to match. So:
 *
 *   • CAPPED at {@see FeeAccrualService::unchargedFine()} — the fine the debt's OWN
 *     frozen rule still supports. That is the honest ceiling: it is what the nightly
 *     sweep would charge, so the till and the sweep can never disagree, and a sheet
 *     held across the sweep re-derives to 0 instead of charging the same ₹100 twice.
 *     A line with no allocation has no rule at all, so its ceiling is zero.
 *   • ATTRIBUTED — `fine_charged_by` + `fine_reason` on the receipt line, the mirror
 *     of `discount_granted_by` + `discount_reason` (2026_10_12_000001). A concession
 *     naming its author while a penalty stayed anonymous was the asymmetry that made
 *     the ledger unarguable in one direction only.
 *   • OVERRIDABLE, but only DELIBERATELY. A caller that has checked a human's
 *     authority may send `fine_override => true` WITH a `fine_reason`; anything
 *     above the ceiling without both is clamped to the ceiling. As with the
 *     discount, WHICH ability that is stays the caller's decision — the panel and
 *     the admin API both require `fees.discount`, reusing the existing "may adjust a
 *     bill away from its computed figure" privilege rather than inventing a
 *     permission that production would not have until someone seeded it by hand.
 *
 * collectAgainstDues(): apply one undifferentiated amount (e.g. an approved offline
 * payment) against the student's outstanding allocations, oldest due first, through
 * the same collect() path.
 *
 * revert(): reverses a receipt — restores every allocation's balance,
 * stamps the revert metadata and fires
 * {@see FeePaymentReverted}. Authorization (`fees.collect` / `fees.revert`) is enforced
 * by the caller (the page/action); this service is the shared state change.
 */
class FeeCollectionService
{
    /** What a receipt line says when the fine is simply the debt's own rule. */
    /** Label of the receipt line that holds money paid beyond the dues. */
    public const ADVANCE_LABEL = 'Advance / unallocated payment';

    public const RULE_FINE_REASON = 'Late fee charged under this fee\'s own late-payment rule';

    /**
     * Collect a payment for a student across one or more lines.
     *
     * Each line is either an allocation line — ['allocation_id', 'amount', 'discount',
     * 'fine'] — or a free-hand line — ['label', 'amount', 'fee_type_id']. `$meta` holds
     * ['date', 'mode', 'reference_no', 'note', 'collected_by'].
     *
     * A discount line may additionally carry ['fee_discount_id', 'discount_reason',
     * 'discount_granted_by']; the last two also read from `$meta` when the caller
     * grants one reason for the whole receipt (the Collect Fees screen does). They are
     * per-LINE as well as per-meta because the online-gateway path stores the lines in
     * the transaction payload and replays them through here from a webhook, where the
     * meta the panel built is not all that survives.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $meta
     *
     * @throws NothingToCollectException when no line carries an amount, a discount
     *                                   or a fine — see below.
     */
    public function collect(Student $student, array $lines, array $meta = []): FeePayment
    {
        $lines = array_values(array_filter($lines, fn ($l): bool => (float) ($l['amount'] ?? 0) > 0
            || (float) ($l['discount'] ?? 0) > 0
            || (float) ($l['fine'] ?? 0) > 0));

        // NOTHING IS MINTED FOR AN EMPTY BASKET (R1).
        //
        // This guard is OUTSIDE the transaction, and that is the whole point. The
        // Collect Fees screen used to post first and tidy up after: collect() ran,
        // burned a receipt sequence, committed the FeePayment row and fired
        // FeePaymentRecorded — which posts to the ledger and messages the guardian
        // — and only then did the page force-delete the row on discovering that
        // every line was zero. The guardian had already been told "We have
        // received 0.00 towards fees", the ledger already carried a zero-value
        // entry, and the receipt number was gone for good: the series is per
        // branch, monotonic and audited, so a burned number is a permanent hole
        // an auditor flags. A receipt number is not a resource you can reserve
        // and return.
        //
        // A discount-only or fine-only receipt IS real — the filter above keeps
        // those — so the test is "is there any line at all", not "is any money
        // changing hands". Callers holding a human's input check first and phrase
        // the refusal in their own words; this is the invariant behind them.
        if ($lines === []) {
            throw NothingToCollectException::make();
        }

        // ONE GATE: "may money be recorded on this date, on this branch?"
        // Asked before the transaction opens, for the same reason the
        // empty-basket guard above is: nothing may be minted for a write that
        // is going to be refused.
        $this->assertMoneyMayBeRecordedOn(
            Arr::get($meta, 'date') ?: now()->toDateString(),
            (int) $student->branch_id,
            (string) Arr::get($meta, 'mode', 'cash'),
        );

        return DB::transaction(function () use ($student, $lines, $meta): FeePayment {
            $branch = Branch::withoutGlobalScopes()->find($student->branch_id);

            $seq = $this->nextReceiptSeq(
                (int) $student->branch_id,
                (int) $student->school_id,
                (int) ($branch?->invoice_start ?: config('fees.receipts.start', 1)),
            );
            $receiptNo = ($branch?->invoice_prefix ?: (string) config('fees.receipts.prefix', 'RCPT')).$seq;

            $paid = 0.0;
            $discount = 0.0;
            $fine = 0.0;

            // One instance for the whole receipt: FeeAccrualService caches each
            // tenant's holiday calendar, so sharing it across the lines is one
            // query rather than one per line.
            $accrual = app(FeeAccrualService::class);

            $payment = FeePayment::create([
                'school_id' => $student->school_id,
                'branch_id' => $student->branch_id,
                'session_id' => current_session_id(),
                'student_id' => $student->id,
                'receipt_no' => $receiptNo,
                'receipt_seq' => $seq,
                'payment_date' => Arr::get($meta, 'date') ?: now()->toDateString(),
                'total_amount' => 0,
                'paid_amount' => 0,
                'discount_amount' => 0,
                'fine_amount' => 0,
                'mode' => Arr::get($meta, 'mode', 'cash'),
                'reference_no' => Arr::get($meta, 'reference_no'),
                'note' => Arr::get($meta, 'note'),
                'collected_by' => Arr::get($meta, 'collected_by', auth()->id()),
                'status' => 'completed',
            ]);

            foreach ($lines as $line) {
                // Money on a receipt line is never negative — a reversal is what
                // revert() is for, not a minus figure smuggled through a payload.
                $lineAmount = max(0, round((float) ($line['amount'] ?? 0), 2));
                $lineDiscount = max(0, round((float) ($line['discount'] ?? 0), 2));
                $lineFine = max(0, round((float) ($line['fine'] ?? 0), 2));

                $allocation = null;
                $feeTypeId = $line['fee_type_id'] ?? null;
                $label = $line['label'] ?? null;

                if (! empty($line['allocation_id'])) {
                    $allocation = FeeStudentAllocation::withoutGlobalScopes()->find($line['allocation_id']);
                }

                // THE FINE CEILING — what this debt's OWN frozen rule still supports.
                //
                // Deliberately re-derived HERE and not taken from the payload. The
                // Collect Fees screen snapshots a suggested fine into the page when
                // the sheet is LOADED; the nightly accrual sweep (03:45) can charge
                // that very fine before the sheet is submitted, and adding the stale
                // suggestion on top charged the same ₹100 twice — leaving a residual
                // on a family that had just paid in full. unchargedFine() answers
                // "what is STILL to charge", so it is 0 the moment the sweep has run
                // and the two paths can no longer disagree.
                //
                // A line with no allocation carries no rule, so nothing supports a
                // fine on it at all — its ceiling is zero.
                $ceiling = $allocation !== null
                    ? round($accrual->unchargedFine($allocation), 2)
                    : 0.0;

                $override = $lineFine > $ceiling + 0.001 && $this->fineOverrideAllowed($line, $meta);

                if (! $override) {
                    $lineFine = min($lineFine, $ceiling);
                }

                if ($allocation) {
                    // CAP — a concession may never exceed what is still owed on the
                    // line. Uncapped, a discount larger than the balance drives
                    // payable (amount − discount + fine) NEGATIVE: the allocation then
                    // reads as the school owing the student, and revert() — which only
                    // subtracts back — cannot restore a figure that was never coherent.
                    // The cap lives HERE, not in the callers, because four paths post
                    // through this method (Collect Fees, POST /admin/fees/collect, the
                    // gateway return, offline-payment approval) and only one of them
                    // used to check anything. The admin API's pre-flight 409 on
                    // amount + discount stays as the caller-facing error; this is the
                    // invariant no path can get round.
                    $lineDiscount = min($lineDiscount, $allocation->balance());

                    $allocation->discount_amount = round((float) $allocation->discount_amount + $lineDiscount, 2);
                    $allocation->fine_amount = round((float) $allocation->fine_amount + $lineFine, 2);
                    // The fine is charged (payable ↑) AND collected (paid ↑) by this
                    // receipt: paid advances by amount + fine so a full pay leaves a
                    // zero balance instead of re-owing the fine it just printed.
                    $allocation->paid_amount = round((float) $allocation->paid_amount + $lineAmount + $lineFine, 2);
                    $allocation->syncStatus()->save();

                    $feeTypeId ??= $allocation->feeMaster?->fee_type_id;
                }

                // forceFill rather than ::create(): `fine_charged_by` / `fine_reason`
                // are not on FeePaymentDetail::$fillable, and a mass-assignment drop
                // would silently lose the attribution. Every value here is built locally, so nothing
                // user-supplied reaches a column it was not meant for.
                $detail = new FeePaymentDetail;
                $detail->forceFill([
                    'school_id' => $payment->school_id,
                    'branch_id' => $payment->branch_id,
                    'fee_payment_id' => $payment->id,
                    'fee_student_allocation_id' => $allocation?->id,
                    'fee_type_id' => $feeTypeId,
                    'label' => $label,
                    'amount' => $lineAmount,
                    'discount_amount' => $lineDiscount,
                    // Who wrote the debt off, and why. Stamped only when there IS a
                    // concession, so an ordinary line stays NULL instead of naming a
                    // "granter" of nothing. The actor is taken from the line/meta and
                    // NOT from auth(): the gateway return mints its receipt inside a
                    // webhook where there is no authenticated user, and falling back to
                    // auth() there would silently attribute the grant to nobody.
                    'discount_granted_by' => $lineDiscount > 0 ? $this->discountActor($line, $meta) : null,
                    'discount_reason' => $lineDiscount > 0 ? $this->discountReason($line, $meta) : null,
                    'fee_discount_id' => $lineDiscount > 0 ? ($line['fee_discount_id'] ?? null) : null,
                    'fine_amount' => $lineFine,
                    // Who levied it, and why — the mirror of the two fields above.
                    // Stamped only when there IS a fine, so an ordinary line stays
                    // NULL rather than naming the author of nothing.
                    'fine_charged_by' => $lineFine > 0 ? $this->fineActor($line, $meta) : null,
                    'fine_reason' => $lineFine > 0 ? $this->fineReason($line, $meta, $override) : null,
                ]);
                $detail->save();

                // Receipt money = what was actually received: base amount + fine.
                // The ledger event posts paid_amount, so ledger totals match too.
                $paid += $lineAmount + $lineFine;
                $discount += $lineDiscount;
                $fine += $lineFine;
            }

            $payment->update([
                'paid_amount' => round($paid, 2),
                'discount_amount' => round($discount, 2),
                'fine_amount' => round($fine, 2),
                'total_amount' => round($paid, 2),
            ]);

            // An over-payment stays on the receipt as a label-only "Advance /
            // unallocated payment" line. Turning it into spendable credit is left
            // to a listener of FeePaymentRecorded.
            event(FeePaymentRecorded::fromPayment($payment->load('details.feeType')));

            return $payment->refresh();
        });
    }

    /**
     * Collect one undifferentiated amount (e.g. an approved offline payment) AGAINST
     * the student's outstanding allocations, oldest due first (undated dues last).
     * Builds allocation lines worth min(remaining, balance) each — partial application
     * is allowed — and funnels them through {@see collect()}, the same posting path as
     * the Collect Fees screen, so the dues actually drop. Any remainder beyond total
     * outstanding dues stays on the receipt as an unallocated advance line (the model
     * already supports label-only {@see FeePaymentDetail} rows), keeping ledger totals
     * equal to the money received.
     *
     * An amount of zero produces no lines at all and therefore raises
     * {@see NothingToCollectException} rather than minting an empty receipt: an
     * approved offline payment of nothing is a data error, not a receipt.
     *
     * @param  array<string, mixed>  $meta  same keys as {@see collect()}
     *
     * @throws NothingToCollectException when $amount rounds to zero or less.
     */
    public function collectAgainstDues(Student $student, float $amount, array $meta = [], string $advanceLabel = self::ADVANCE_LABEL): FeePayment
    {
        $remaining = round(max(0, $amount), 2);
        $lines = [];

        $allocations = FeeStudentAllocation::query()
            ->where('student_id', $student->id)
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            if ($remaining <= 0) {
                break;
            }

            $balance = $allocation->balance();

            if ($balance <= 0) {
                continue;
            }

            $apply = round(min($remaining, $balance), 2);
            $lines[] = ['allocation_id' => $allocation->id, 'amount' => $apply];
            $remaining = round($remaining - $apply, 2);
        }

        if ($remaining > 0) {
            $lines[] = ['label' => $advanceLabel, 'amount' => $remaining];
        }

        return $this->collect($student, $lines, $meta);
    }

    /**
     * Reverse a completed receipt: restore each allocation's balance, unwind the
     * credit ledger, mark the payment reverted (+ reason/actor) and fire
     * {@see FeePaymentReverted}. No-op if already reverted.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A REFUNDED RECEIPT IS REFUSED HERE, NOT IN EACH SURFACE THAT OFFERS REVERT
     * ─────────────────────────────────────────────────────────────────────────
     * A refund has ALREADY restored part of the dues and already reversed part of
     * the ledger entry ({@see FeeRefundService::issue()} subtracts each refunded
     * line from `paid_amount`). Reverting on top of that restores the same money a
     * second time, so the family is re-billed the whole receipt while the school
     * keeps the unrefunded remainder credited to nobody.
     *
     * There are THREE surfaces that reverse a receipt — the receipts table, the
     * student profile's Fees tab and {@see ChequeService::bounce()} —
     * and the guard had been written once per surface, so the profile page had none
     * and the cheque path had none. That is exactly the shape of bug a predicate
     * copied into callers produces, so the check lives HERE, on the one method all
     * three go through, and any fourth entry point inherits it.
     * {@see FeeRefundService::hasLiveRefunds()} is the canonical question.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * EXCEPT A BOUNCE, WHICH MUST REVERSE — AND MUST NOT SWALLOW THE REFUND
     * ─────────────────────────────────────────────────────────────────────────
     * A bounced cheque is the one case where reversal is not optional: the money
     * never arrived, so the dues have to come back whatever else has happened. The
     * caller says so with `$evenIfRefunded` — a deliberate opt-in, never a default,
     * so no new revert surface can acquire the exemption by accident.
     *
     * What then happens to money already REFUNDED against a payment that never
     * cleared is a product decision, and this is the one taken: THE FAMILY OWES IT.
     * The school handed over cash against a cheque that bounced; that is a debt, not
     * a loss to absorb. Arithmetically it is the remainder the `max(0, …)` floor
     * below would otherwise eat — restore ₹25,400 of `paid_amount` that a ₹10,000
     * refund has already reduced to ₹15,400 and the sum is −₹10,000, which the floor
     * turns into zero and the ₹10,000 simply disappears. So it is carried onto the
     * allocation's `fine_amount` instead, the same column
     * {@see ChequeService::applyPenalty()} already uses to make a
     * bounce cost the family more, and the reason recorded on the receipt says why.
     *
     * THE ALTERNATIVE, for whoever wants to overrule this: absorb it — let the floor
     * clamp, and treat the paid-out refund as the school's loss, to be chased outside
     * the fee ledger. That keeps the fee statement free of a charge the family may
     * dispute, at the cost of the money being visible nowhere in the system, which is
     * the failure mode this whole sweep exists to remove. The known trade-off of the
     * choice made here is that `fine_amount` carries a figure that is not a
     * late-payment fine, which suppresses rule-based fines on that line until the
     * rule catches up — the same trade-off the bounce penalty already makes.
     *
     * @param  bool  $evenIfRefunded  reverse even though live refunds stand against
     *                                this receipt (a bounced instrument). The
     *                                refunded money is carried back as a charge.
     *
     * @throws RuntimeException when the receipt carries live refunds and the caller
     *                          has not opted in.
     */
    public function revert(FeePayment $payment, ?string $reason = null, ?int $userId = null, bool $evenIfRefunded = false): FeePayment
    {
        if ($payment->isReverted()) {
            return $payment;
        }

        $refunded = app(FeeRefundService::class)->hasLiveRefunds($payment);

        if ($refunded && ! $evenIfRefunded) {
            throw new RuntimeException(
                'Receipt '.$payment->receipt_no.' has already been refunded in part or in full, so it cannot be '
                .'reverted — reverting would restore dues the refund has already restored and re-bill the family '
                .'for money the school has given back. Void or reverse the refund first.',
            );
        }

        return DB::transaction(function () use ($payment, $reason, $userId, $refunded): FeePayment {
            $details = $payment->details()->with('allocation')->get();
            $carried = 0.0;

            foreach ($details as $detail) {
                $allocation = $detail->allocation;

                if (! $allocation) {
                    continue;
                }

                // Symmetric with collect(): paid advanced by amount + fine, so the
                // revert walks both back (R3).
                $restored = round((float) $allocation->paid_amount - (float) $detail->amount - (float) $detail->fine_amount, 2);

                // What the floor is about to eat. Zero on an ordinary revert; on a
                // bounced-and-partly-refunded receipt it is precisely the cash the
                // school paid out against a payment that never arrived.
                $unrestorable = $restored < 0 ? round(-$restored, 2) : 0.0;

                $allocation->paid_amount = round(max(0, $restored), 2);
                $allocation->discount_amount = round(max(0, (float) $allocation->discount_amount - (float) $detail->discount_amount), 2);
                $allocation->fine_amount = round(max(0, (float) $allocation->fine_amount - (float) $detail->fine_amount), 2);

                if ($refunded && $unrestorable > 0) {
                    $allocation->fine_amount = round((float) $allocation->fine_amount + $unrestorable, 2);
                    $carried = round($carried + $unrestorable, 2);
                }

                $allocation->syncStatus()->save();
            }

            if ($carried > 0) {
                // Say it on the receipt itself. A charge a family cannot trace to a
                // decision is the same problem as money that vanishes.
                $reason = trim((string) $reason).' — '.number_format($carried, 2)
                    .' already refunded against this receipt has been carried back as a charge, because the payment it was refunded from never cleared.';
                $reason = mb_substr(trim($reason, " \t\n\r\0\x0B-"), 0, 255);
            }

            $payment->update([
                'status' => 'reverted',
                'reverted_at' => now(),
                'reverted_by' => $userId ?? auth()->id(),
                'revert_reason' => $reason,
            ]);

            // THE CREDIT LEDGER IS PART OF THE REVERSAL, NOT A SEPARATE CHORE.
            // FeePaymentReverted LAST, so listeners see a receipt that is already
            // marked reverted with its dues already restored.
            event(FeePaymentReverted::fromPayment($payment->load('details.feeType')));

            return $payment->refresh();
        });
    }

    /**
     * The user credited with granting a line's discount: the line's own actor, else the
     * receipt-wide one, else the collector. auth() is the LAST resort — see the WHY at
     * the FeePaymentDetail::create() call.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $meta
     */
    protected function discountActor(array $line, array $meta): ?int
    {
        $actor = $line['discount_granted_by']
            ?? Arr::get($meta, 'discount_granted_by')
            ?? Arr::get($meta, 'collected_by')
            ?? auth()->id();

        return $actor !== null ? (int) $actor : null;
    }

    /**
     * The reason recorded against a line's discount: the line's own, else the one the
     * caller gave for the whole receipt. Both callers that can grant a discount refuse
     * to post without one, so a NULL here means a historical row or a system path.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $meta
     */
    protected function discountReason(array $line, array $meta): ?string
    {
        $reason = trim((string) ($line['discount_reason'] ?? Arr::get($meta, 'discount_reason') ?? ''));

        return $reason !== '' ? mb_substr($reason, 0, 255) : null;
    }

    /**
     * May this line charge MORE fine than the debt's own rule supports?
     *
     * Only when the caller says so DELIBERATELY and says why. Two conditions, both
     * required:
     *
     *   • an explicit `fine_override` flag on the line or the meta. A caller opts
     *     in; nothing opts in by accident, and no payload key that a browser or an
     *     app happens to post can set it — the panel and the admin API BUILD this
     *     flag server-side after checking the user's ability, they never copy it
     *     out of the request.
     *   • a reason. A penalty above the rule is a human decision and the receipt
     *     line has to say what it was.
     *
     * WHO is allowed to set the flag is not decided here, for exactly the reason
     * the discount's `fees.discount` check is not: the gateway return and the
     * offline-payment approval mint receipts with no authenticated user to ask.
     * Both human-facing callers require `fees.discount` — the existing "may adjust
     * a bill away from its computed figure" privilege — rather than a new
     * `fees.fine`, because the production deploy migrates but never seeds, so a
     * fresh permission would not exist on an existing install until somebody granted it by hand
     * and the gate would be dead on arrival.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $meta
     */
    protected function fineOverrideAllowed(array $line, array $meta): bool
    {
        $flag = $line['fine_override'] ?? Arr::get($meta, 'fine_override') ?? false;

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN)
            && trim((string) ($line['fine_reason'] ?? Arr::get($meta, 'fine_reason') ?? '')) !== '';
    }

    /**
     * The user credited with levying a line's fine: the line's own actor, else the
     * receipt-wide one, else the collector. auth() is the LAST resort — same
     * reasoning as {@see discountActor()}.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $meta
     */
    protected function fineActor(array $line, array $meta): ?int
    {
        $actor = $line['fine_charged_by']
            ?? Arr::get($meta, 'fine_charged_by')
            ?? Arr::get($meta, 'collected_by')
            ?? auth()->id();

        return $actor !== null ? (int) $actor : null;
    }

    /**
     * Why a line's fine was charged.
     *
     * An override carries the operator's own words (it cannot be an override
     * without them — see {@see fineOverrideAllowed()}). An ordinary fine records
     * the rule instead of a NULL, so a parent disputing a receipt is answered with
     * "this is your fee's late-payment rule" rather than a bare figure with nothing
     * behind it.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $meta
     */
    protected function fineReason(array $line, array $meta, bool $override): ?string
    {
        $reason = trim((string) ($line['fine_reason'] ?? Arr::get($meta, 'fine_reason') ?? ''));

        if ($reason === '') {
            return $override ? null : self::RULE_FINE_REASON;
        }

        return mb_substr(($override ? 'Manual fine: ' : '').$reason, 0, 255);
    }

    /**
     * The next receipt sequence for a branch, taken from that branch's counter row
     * in `fee_receipt_sequences` under a row lock.
     *
     * ── WHY NOT max(receipt_seq) + 1 ─────────────────────────────────────────
     * That is what this method used to do, and it could both GAP and COLLIDE:
     *
     *  • It read through {@see FeePayment}, which soft-deletes. The three tenancy
     *    scopes were lifted but SoftDeletingScope was not, so a soft-deleted
     *    receipt was invisible to MAX() and its number was handed straight back
     *    out. The unique key that ought to have caught that is
     *    `(branch_id, receipt_no)`, and it was deliberately made soft-delete-aware
     *    (2026_09_09_000001 / 2026_09_11_000001) so a live row MAY reuse an
     *    archived row's key. Both decisions are defensible on their own; together
     *    they mint duplicate receipt numbers and the database says nothing.
     *  • `lockForUpdate()` on an aggregate locks the rows the aggregate scanned.
     *    For a branch that has never taken a payment that is no rows, so two
     *    concurrent first collections both read 0 and both mint 1.
     *  • `invoice_start` — a free-text admin field on the Branches form — was
     *    folded into the expression on EVERY call, so raising it mid-year jumped
     *    the series and left a permanent hole with no warning.
     *
     * ── HOW GAPLESSNESS IS GUARANTEED NOW ────────────────────────────────────
     *  1. The counter is a real row, so `SELECT … FOR UPDATE` has something to
     *     lock even for a branch with no receipts. Concurrent collections on one
     *     branch queue behind that lock instead of reading the same number twice.
     *  2. The read-and-advance happens INSIDE collect()'s transaction. If the
     *     receipt is not written the increment is not written either, so a failed
     *     collection does not consume a number.
     *  3. collect() refuses an empty basket before the transaction opens, so no
     *     number is minted for a receipt that is about to be thrown away.
     *  4. `(branch_id, receipt_seq)` is unique across ALL rows including archived
     *     ones (added by the same migration where the data allows), which is the
     *     backstop: even a future path that recomputed max+1 could not write a
     *     number this branch has already issued.
     *
     * ── invoice_start IS READ EXACTLY ONCE PER BRANCH, EVER ──────────────────
     * It seeds the counter the first time a branch mints a receipt, and is never
     * consulted again. Raising it afterwards therefore cannot move the series —
     * which is the gap in (c), closed at the source rather than by asking an
     * administrator to be careful. The Branches form disables the field once the
     * branch has a counter and says why.
     *
     * The seed is `max(invoice_start, highest number ever used + 1)`. The second
     * term matters for a branch whose receipts predate this table, or were
     * imported: a counter seeded under the numbers already on paper would issue
     * duplicates from its very first receipt.
     */
    protected function nextReceiptSeq(int $branchId, int $schoolId, int $invoiceStart): int
    {
        $row = DB::table('fee_receipt_sequences')
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // insertOrIgnore, not insert: two first-collections on the same branch
            // can both arrive here, and the unique key on branch_id decides which
            // one creates the counter. The loser's insert is ignored and the
            // re-read below picks up the winner's row, still under a lock — so
            // both end up on the same single series rather than racing to mint 1.
            DB::table('fee_receipt_sequences')->insertOrIgnore([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                // THE ONLY LINE IN THIS CLASS THAT READS invoice_start, and it
                // runs at most once per branch in the branch's whole life.
                'next_seq' => max($this->highestUsedSeq($branchId) + 1, max(1, $invoiceStart)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('fee_receipt_sequences')
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        // Belt and braces for a counter that somehow sits UNDER the history it is
        // meant to lead — a restored branch, a partial restore from a dump, a row
        // edited by hand. Never issue a number this branch has used before,
        // whatever the counter says. The cost is one index seek: the backstop key
        // `(branch_id, receipt_seq)` makes MAX(receipt_seq) for a branch a lookup
        // rather than a scan, and where that key could not be added (historical
        // duplicates) this check is the only thing standing in for it.
        //
        // invoice_start is POINTEDLY absent from this floor. Folding it in here
        // instead of only at seeding time reproduces defect (c) exactly — a probe
        // caught it doing so: with the counter at 5, raising invoice_start to 9000
        // still minted INV9000 and left 8,995 missing numbers behind it. The
        // counter, once seeded, is the whole of the answer.
        $seq = max(1, (int) ($row->next_seq ?? 1), $this->highestUsedSeq($branchId) + 1);

        DB::table('fee_receipt_sequences')
            ->where('branch_id', $branchId)
            ->update(['next_seq' => $seq + 1, 'updated_at' => now()]);

        return $seq;
    }

    /**
     * The highest receipt number this branch has EVER used, 0 if none.
     *
     * Read through the query builder rather than {@see FeePayment} on purpose:
     * this must see soft-deleted and out-of-scope receipts. That is precisely
     * what the model's global scopes hide, and precisely what the old
     * `MAX(receipt_seq) + 1` got wrong — a soft-deleted receipt was invisible to
     * it, so its number was issued a second time.
     */
    protected function highestUsedSeq(int $branchId): int
    {
        return (int) DB::table('fee_payments')
            ->where('branch_id', $branchId)
            ->max('receipt_seq');
    }

    /**
     * The one gate on "may money be recorded on this date?". The mode must be a
     * known receipt mode (`online` is used by the payments package); then the
     * bound {@see CollectionGuard} is asked (a no-op unless an accounting module
     * binds one that refuses closed periods).
     *
     * The branch comes from the STUDENT, never from current_branch_id(): queued
     * and console callers have no ambient branch.
     */
    protected function assertMoneyMayBeRecordedOn(string $date, int $branchId, string $mode): void
    {
        if (! array_key_exists($mode, FeePayment::MODES)) {
            throw new RuntimeException('Unsupported payment mode ['.$mode.'].');
        }

        app(CollectionGuard::class)->assertMayRecord($date, $branchId, $mode);
    }
}
