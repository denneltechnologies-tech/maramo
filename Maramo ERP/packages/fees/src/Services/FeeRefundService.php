<?php

namespace Classivo\Fees\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Classivo\Fees\Events\FeeRefunded;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeePaymentDetail;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Models\FeeRefundDecision;
use Classivo\Fees\Models\FeeRefundLine;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\Money;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FeeRefundService — full and partial refunds against a collection receipt.
 *
 * Lifecycle: request() -> approve()/reject()/void() -> issue() -> markPaid().
 *
 *  • APPROVALS. The number of approvals a refund needs comes from
 *    {@see FeeRefunds::requiredApprovals()} (config `fees.refunds.*`). Every
 *    transition is written to `fee_refund_decisions`, inside the same
 *    transaction as the transition, so the audit trail cannot drift from the
 *    refund. Two-level refunds require two DISTINCT approvers.
 *  • NUMBERING. Credit notes have their own per-branch series, allocated from
 *    a lockable counter row (`fee_credit_note_sequences`) inside the issuing
 *    transaction, so a failed issue does not consume a number.
 *  • REVERSAL SHAPE. Restoring dues subtracts from `paid_amount` exactly as
 *    FeeCollectionService::revert() does; the original receipt stands.
 *  • THE ACCOUNTING SEAM. This service posts nothing to any ledger. issue()
 *    fires {@see FeeRefunded}, a plain-scalar event, at the single moment money
 *    moves. An accounting module listens for it.
 *  • IDEMPOTENT ISSUE. issue() re-reads the refund under a row lock and returns
 *    untouched if it is already issued; the unique (branch, credit_note_seq)
 *    index backs that up.
 */
class FeeRefundService
{
    // ------------------------------------------------------------------ read

    /**
     * How much of a receipt is still refundable.
     *
     * The money actually received on a receipt is `paid_amount` (which already
     * includes the fines the receipt both charged and collected — see
     * FeeCollectionService::collect()), less every refund against it that has not
     * been rejected or voided. A refund still sitting at `requested` counts: two
     * clerks each raising a full refund on the same receipt and both being
     * approved is precisely the failure this figure exists to prevent, and it has
     * to be prevented at REQUEST time, not at issue time.
     */
    public function refundableFor(FeePayment $payment): float
    {
        return round(max(0, (float) $payment->paid_amount - $this->refundedTotal($payment)), 2);
    }

    /**
     * What live refunds have already claimed from this receipt.
     *
     * Tenant and branch scopes lifted for the reason every other money read in
     * this service lifts them: a refund raised on the receipt's own branch must
     * still be counted by a caller reading from anywhere else, or the figure
     * silently reads as zero and every guard built on it opens.
     */
    public function refundedTotal(FeePayment $payment): float
    {
        return round((float) FeeRefund::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->where('fee_payment_id', $payment->id)
            ->live()
            ->sum('amount'), 2);
    }

    /**
     * IS ANY LIVE REFUND STANDING AGAINST THIS RECEIPT? — the canonical answer to
     * the question that gates every REVERT surface.
     *
     * Reverting a receipt that has been partly refunded restores dues the refund
     * already restored and reverses a ledger entry the refund already reversed in
     * part: the family is re-billed the whole receipt while the school keeps the
     * unrefunded remainder, credited nowhere.
     *
     * This lives on the SERVICE, not on a table class, so every revert or refund
     * entry point asks the same question instead of reimplementing the guard.
     */
    public function hasLiveRefunds(FeePayment $payment): bool
    {
        return $this->refundedTotal($payment) > 0;
    }

    /**
     * How much of ONE receipt line is still refundable: the money received on it
     * (amount + fine) less what live refunds have already claimed from it.
     */
    public function refundableForDetail(FeePaymentDetail $detail): float
    {
        $received = round((float) $detail->amount + (float) $detail->fine_amount, 2);

        $claimed = (float) FeeRefundLine::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->where('fee_payment_detail_id', $detail->id)
            ->whereHas('refund', fn ($q) => $q->withoutGlobalScopes([TenantScope::class, BranchScope::class])->live())
            ->sum('amount');

        return round(max(0, $received - $claimed), 2);
    }

    // ------------------------------------------------------------------ request

    /**
     * Raise a refund against a receipt.
     *
     * `$data` carries:
     *   amount         float   — the total to refund (required unless `lines` given)
     *   lines          array   — [fee_payment_detail_id => amount] for a per-head
     *                            refund; when absent the amount is apportioned
     *                            across the receipt's lines, largest first
     *   reason_code    string  — one of FeeRefunds::REASON_CODES (required)
     *   reason_detail  string  — free text beside the code, never instead of it
     *   mode           string  — original | cheque | bank_transfer
     *   reference_no   string  — cheque number / UTR
     *   restore_dues   bool    — overrides the reason code's default
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the receipt cannot be refunded, or the figure
     *                          exceeds what is left on it
     */
    public function request(FeePayment $payment, array $data, ?User $actor = null): FeeRefund
    {
        $reasonCode = (string) Arr::get($data, 'reason_code', '');

        if (! array_key_exists($reasonCode, FeeRefunds::REASON_CODES)) {
            throw new RuntimeException('Choose a refund reason.');
        }

        $mode = (string) Arr::get($data, 'mode', 'original');

        if (! array_key_exists($mode, FeeRefunds::MODES)) {
            throw new RuntimeException('Choose how the refund is being returned.');
        }

        return DB::transaction(function () use ($payment, $data, $actor, $reasonCode, $mode): FeeRefund {
            // Re-read the receipt under a lock. Two clerks refunding the same
            // receipt at the same moment is the whole reason this is a lock and
            // not a read: the second must see the first's refund in the
            // refundable figure below.
            $receipt = FeePayment::query()
                ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
                ->lockForUpdate()
                ->find($payment->id);

            if ($receipt === null) {
                throw new RuntimeException('That receipt no longer exists.');
            }

            if ($receipt->isReverted()) {
                // A reverted receipt has already given the dues back in full and
                // its money is accounted for by the contra entry. Refunding it as
                // well would return the same rupees twice.
                throw new RuntimeException('This receipt has been reverted; there is nothing left to refund.');
            }

            $refundable = $this->refundableFor($receipt);

            if ($refundable <= 0) {
                throw new RuntimeException('This receipt has already been fully refunded.');
            }

            $restoreDues = array_key_exists('restore_dues', $data)
                ? (bool) $data['restore_dues']
                : FeeRefunds::restoresDues($reasonCode);

            [$lines, $amount] = $this->resolveLines($receipt, $data, $restoreDues);

            if ($amount <= 0) {
                throw new RuntimeException('Enter an amount to refund.');
            }

            if ($amount > $refundable + 0.001) {
                throw new RuntimeException('Only '.number_format($refundable, 2).' is still refundable on this receipt.');
            }

            // ASKING FOR MORE THAN CAN BE PLACED IS A REFUSAL, NOT A TRIM.
            // resolveLines() caps every apportioned line at what that line has
            // left, so a request for 99,999 against a receipt with 11,400
            // remaining comes back as a perfectly valid 11,400 refund — and the
            // clerk would have been paid out a different figure from the one they
            // typed, with nothing on screen to say so. Money is the one place a
            // silent reduction is worse than an error.
            //
            // THE EXPLICIT PER-HEAD PATH IS NO LONGER EXEMPT. It used to be, on
            // the grounds that each line had been capped against its own
            // remainder — but "capped" was the whole defect: the operator typed a
            // figure per head and was quietly given a smaller one. resolveLines()
            // now refuses per line, naming the head and both figures; this stays
            // as the backstop that covers the total, whichever shape was sent.
            $explicitAsk = round(array_sum(array_map(
                fn ($v): float => round(max(0, (float) $v), 2),
                (array) Arr::get($data, 'lines', []),
            )), 2);

            $requested = $explicitAsk > 0
                ? $explicitAsk
                : round(max(0, (float) Arr::get($data, 'amount', 0)), 2);

            if ($requested > $amount + 0.001) {
                throw new RuntimeException(
                    'Only '.number_format($amount, 2).' can still be refunded across this receipt\'s lines'
                    .' — previous refunds have already claimed the rest.'
                );
            }

            $required = FeeRefunds::requiredApprovals($amount);

            $refund = FeeRefund::create([
                'school_id' => $receipt->school_id,
                'branch_id' => $receipt->branch_id,
                'session_id' => $receipt->session_id,
                'student_id' => $receipt->student_id,
                'fee_payment_id' => $receipt->id,
                'amount' => $amount,
                'reason_code' => $reasonCode,
                'reason_detail' => $this->trimText(Arr::get($data, 'reason_detail')),
                'mode' => $mode,
                'original_mode' => $receipt->mode,
                'reference_no' => $this->trimText(Arr::get($data, 'reference_no'), 191),
                'restore_dues' => $restoreDues,
                'status' => 'requested',
                'required_approvals' => $required,
                'approvals_received' => 0,
                'threshold_at_request' => FeeRefunds::threshold(),
                'requested_by' => $actor?->getAuthIdentifier() ?? auth()->id(),
                'requested_at' => now(),
            ]);

            foreach ($lines as $line) {
                FeeRefundLine::create($line + [
                    'school_id' => $refund->school_id,
                    'branch_id' => $refund->branch_id,
                    'fee_refund_id' => $refund->id,
                ]);
            }

            // The audit trail starts here, in `fee_refund_decisions`.
            $this->logDecision($refund, 'requested', $this->requestRemark($refund), $actor?->getAuthIdentifier() ?? auth()->id());

            // Below the threshold nobody has to look at it — but SOMETHING still
            // has to be on the record saying so, with the figure that made it
            // true. `auto_approved` is its own decision word for exactly that
            // reason: it must never read as though a person approved it.
            if ($required === 0) {
                $refund->forceFill([
                    'status' => 'approved',
                    'approved_by' => null,
                    'approved_at' => now(),
                ])->save();

                $this->logDecision(
                    $refund,
                    'auto_approved',
                    'Auto-approved: '.number_format($amount, 2).' is at or below the approval threshold of '
                        .number_format((float) $refund->threshold_at_request, 2).'.',
                    $actor?->getAuthIdentifier() ?? auth()->id(),
                );
            }

            return $refund->refresh();
        });
    }

    // ------------------------------------------------------------------ decide

    /**
     * Record one approval. When the last required approval lands, the refund
     * becomes `approved` — which still does not move money; see {@see issue()}.
     */
    public function approve(FeeRefund $refund, User $actor, ?string $remark = null): FeeRefund
    {
        return DB::transaction(function () use ($refund, $actor, $remark): FeeRefund {
            $locked = $this->lock($refund->id);

            if (! $locked->isPendingApproval()) {
                throw new RuntimeException('This refund has already been decided.');
            }

            // DISTINCT APPROVERS. A two-level refund that one person can approve
            // twice has one level. Only the approvals already applied are read.
            $priorApprovers = FeeRefundDecision::query()
                ->where('fee_refund_id', $locked->id)
                ->whereIn('decision', ['approved', 'auto_approved'])
                ->orderBy('id')
                ->limit(max(1, (int) $locked->approvals_received))
                ->pluck('decided_by')
                ->all();

            if ((int) $locked->approvals_received > 0 && in_array((int) $actor->getAuthIdentifier(), array_map('intval', $priorApprovers), true)) {
                throw new RuntimeException('A second approver is required — you have already approved this refund.');
            }

            $received = (int) $locked->approvals_received + 1;
            $complete = $received >= (int) $locked->required_approvals;

            $locked->forceFill(array_filter([
                'approvals_received' => $received,
                'status' => $complete ? 'approved' : 'requested',
                'approved_by' => $complete ? $actor->getAuthIdentifier() : null,
                'approved_at' => $complete ? now() : null,
            ], fn ($v): bool => $v !== null))->save();

            $this->logDecision($locked, 'approved', $this->decisionRemark($remark, $received, (int) $locked->required_approvals), $actor->getAuthIdentifier());

            return $locked->refresh();
        });
    }

    /** Reject a refund outright. No money moves and none ever will on this row. */
    public function reject(FeeRefund $refund, User $actor, string $remark): FeeRefund
    {
        return DB::transaction(function () use ($refund, $actor, $remark): FeeRefund {
            $locked = $this->lock($refund->id);

            if ($locked->isIssued()) {
                throw new RuntimeException('This refund has already been issued and cannot be rejected.');
            }

            if ($locked->status === 'rejected') {
                return $locked;
            }

            $locked->forceFill([
                'status' => 'rejected',
                'rejected_by' => $actor->getAuthIdentifier(),
                'rejected_at' => now(),
            ])->save();

            $this->logDecision($locked, 'rejected', $this->trimText($remark), $actor->getAuthIdentifier());

            return $locked->refresh();
        });
    }

    /** Withdraw a refund before any money moved (a request raised in error). */
    public function void(FeeRefund $refund, User $actor, string $reason): FeeRefund
    {
        return DB::transaction(function () use ($refund, $actor, $reason): FeeRefund {
            $locked = $this->lock($refund->id);

            if ($locked->isIssued()) {
                throw new RuntimeException('This refund has been issued; void the credit instead.');
            }

            if ($locked->status === 'voided') {
                return $locked;
            }

            $locked->forceFill(['status' => 'voided'])->save();

            $this->logDecision($locked, 'voided', $this->trimText($reason), $actor->getAuthIdentifier());

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ issue

    /**
     * ISSUE THE REFUND — the one method in this service that moves money.
     *
     * In one transaction it restores the dues its lines call for, mints the credit
     * note number, stamps the refund `issued`, and fires {@see FeeRefunded}.
     *
     * Idempotent: the row lock plus the `isIssued()` no-op, backed by the unique
     * (branch, credit_note_seq) index.
     */
    public function issue(FeeRefund $refund, User $actor): FeeRefund
    {
        return $this->applyIssue($refund, $actor);
    }

    /** The real issue transition. */
    protected function applyIssue(FeeRefund $refund, User $actor): FeeRefund
    {
        return DB::transaction(function () use ($refund, $actor): FeeRefund {
            $locked = $this->lock($refund->id);

            // Already done — return it untouched rather than paying again. Exactly
            // revert()'s opening move.
            if ($locked->isIssued()) {
                return $locked;
            }

            if (! $locked->isApproved()) {
                throw new RuntimeException('This refund has not been approved yet.');
            }

            $this->restoreDues($locked);

            $this->stampCreditNote($locked, $actor);

            $this->logDecision($locked, 'issued', $this->issueRemark($locked), $actor->getAuthIdentifier());

            // ACCOUNTING SEAM — the only one in this service. Nothing is posted
            // here; an accounting module listens for FeeRefunded and writes the
            // reversing entry for the refunded portion only.
            event(FeeRefunded::fromRefund($locked->load(['lines.feeType', 'payment'])));

            return $locked->refresh();
        });
    }

    /**
     * Mark a physical payout (cash handed over, cheque written, transfer made)
     * as actually made.
     */
    public function markPaid(FeeRefund $refund, User $actor, ?string $reference = null): FeeRefund
    {
        return DB::transaction(function () use ($refund, $actor, $reference): FeeRefund {
            $locked = $this->lock($refund->id);

            if (! $locked->isIssued() || $locked->payout_status === 'paid') {
                return $locked;
            }

            $locked->forceFill(array_filter([
                'payout_status' => 'paid',
                'paid_at' => now(),
                'reference_no' => $this->trimText($reference, 191) ?: $locked->reference_no,
            ], fn ($v): bool => $v !== null))->save();

            $this->logDecision($locked, 'paid_out', 'Payout made'.($reference ? ' — '.$reference : '').'.', $actor->getAuthIdentifier());

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ gateway

    /**
     * Record a refund that a payment gateway has ALREADY paid back on an online
     * receipt (called by the payments package for partial refunds).
     *
     * The money has left the school's gateway account, so there is nothing to
     * approve: the refund is created, apportioned over the receipt's lines
     * (largest first, as for a typed amount), dues restored, credit note
     * numbered and FeeRefunded fired — in one transaction, with the decision
     * trail saying the gateway did it. Idempotent on the gateway refund id
     * (stored as reference_no): the same refund reported twice is recorded once.
     *
     * @throws RuntimeException when the receipt is reverted or the amount exceeds
     *                          what is still refundable on it
     */
    public function recordGatewayRefund(FeePayment $payment, float $amount, string $gatewayRefundId, string $gateway, ?User $actor = null): FeeRefund
    {
        return DB::transaction(function () use ($payment, $amount, $gatewayRefundId, $gateway, $actor): FeeRefund {
            $existing = FeeRefund::query()
                ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
                ->where('fee_payment_id', $payment->id)
                ->where('reason_code', 'gateway_refund')
                ->where('reference_no', $gatewayRefundId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $refund = $this->request($payment, [
                'reason_code' => 'gateway_refund',
                'reason_detail' => 'Refunded by '.$gateway,
                'mode' => 'original',
                'reference_no' => $gatewayRefundId,
                'amount' => round($amount, 2),
            ], $actor);

            $locked = $this->lock($refund->id);

            if (! $locked->isApproved()) {
                $locked->forceFill(['status' => 'approved', 'approved_by' => null, 'approved_at' => now()])->save();
                $this->logDecision($locked, 'auto_approved', 'Already refunded by '.$gateway.' ('.$gatewayRefundId.').', $actor?->getAuthIdentifier());
            }

            $this->restoreDues($locked);
            $this->stampCreditNote($locked, $actor);
            $locked->forceFill(['payout_status' => 'paid', 'paid_at' => now()])->save();
            $this->logDecision($locked, 'issued', 'Paid back through '.$gateway.' — '.$gatewayRefundId.'.', $actor?->getAuthIdentifier());

            event(FeeRefunded::fromRefund($locked->load(['lines.feeType', 'payment'])));

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ pdf

    /**
     * The credit note PDF — the document the receipt reprint never was.
     *
     * dompdf over the package view `classivo-fees::pdf.credit-note`, in A4, A5 or
     * thermal size. It names the money going back, the heads it came out of, the
     * reason code and who approved it.
     */
    public function creditNotePdf(FeeRefund $refund, string $size = 'a4'): PdfDocument
    {
        $refund->loadMissing(['lines.feeType', 'payment', 'student', 'requestedBy', 'approvedBy', 'issuedBy']);

        $size = in_array($size, ['a4', 'a5', 'thermal'], true) ? $size : 'a4';

        $school = School::find($refund->school_id);
        $branch = Branch::withoutGlobalScopes()->find($refund->branch_id);

        $pdf = Pdf::loadView('classivo-fees::pdf.credit-note', [
            'refund' => $refund,
            'student' => $refund->student,
            'payment' => $refund->payment,
            'school' => $school,
            'branch' => $branch,
            'size' => $size,
            'currencySymbol' => Money::symbol($branch),
            'decisions' => $refund->decisions()->with('decider')->get(),
        ]);

        if ($size === 'thermal') {
            $pdf->setPaper([0, 0, 226.77, 650]);
        } else {
            $pdf->setPaper($size);
        }

        return $pdf;
    }

    // ------------------------------------------------------------------ internals

    /** One refund, re-read under a row lock, across tenant scopes. */
    protected function lock(int $id): FeeRefund
    {
        $refund = FeeRefund::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->lockForUpdate()
            ->find($id);

        if ($refund === null) {
            throw new RuntimeException('That refund no longer exists.');
        }

        return $refund;
    }

    /** One refund by id, across tenant scopes, with the relations a caller wants. */
    public function find(int $id): ?FeeRefund
    {
        return FeeRefund::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->with(['lines.feeType', 'payment', 'student'])
            ->find($id);
    }

    /**
     * Turn the caller's input into refund lines.
     *
     * Two shapes are accepted, and both end in the same per-line rows:
     *  • EXPLICIT — ['lines' => [detail_id => amount]] from the per-head form.
     *  • APPORTIONED — a single ['amount'], spread across the receipt's lines
     *    LARGEST FIRST. Largest-first rather than pro-rata on purpose: a school
     *    refunding ₹5,000 of a ₹30,000 receipt means "take it out of the big head",
     *    and a pro-rata spread would produce five awkward part-refunds of five
     *    heads that no credit note reads well and no allocation wants back.
     *
     * Every line is capped at what is still refundable on ITS receipt line, so an
     * apportionment can never claim money a previous partial refund already took.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    protected function resolveLines(FeePayment $receipt, array $data, bool $restoreDues): array
    {
        $details = FeePaymentDetail::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->with('feeType')
            ->where('fee_payment_id', $receipt->id)
            ->get();

        $explicit = (array) Arr::get($data, 'lines', []);
        $lines = [];
        $total = 0.0;

        if ($explicit !== []) {
            $placed = [];

            foreach ($details as $detail) {
                $want = round(max(0, (float) ($explicit[$detail->id] ?? 0)), 2);
                $placed[$detail->id] = true;

                if ($want <= 0) {
                    continue;
                }

                $remaining = $this->refundableForDetail($detail);

                // ASKING A LINE FOR MORE THAN IT HAS LEFT IS A REFUSAL, NOT A TRIM.
                // This used to `min()` the two, so typing 99,999 against a head
                // with 11,000 left raised a perfectly valid 11,000 refund and paid
                // the operator a figure they never typed, with nothing on screen to
                // say so — the exact harm the apportioned path below refuses, and
                // the form offers no max to stop it. The refusal names both figures
                // because "too much" without them is not actionable at a counter.
                if ($want > $remaining + 0.001) {
                    $received = round((float) $detail->amount + (float) $detail->fine_amount, 2);

                    throw new RuntimeException(sprintf(
                        'Only %s is still refundable on %s — you asked for %s.%s',
                        number_format($remaining, 2),
                        $detail->feeType?->name ?? $detail->label ?? 'that line',
                        number_format($want, 2),
                        // Only say a previous refund took it when one actually
                        // did; otherwise the line simply never held that much.
                        $remaining < $received - 0.001
                            ? ' Previous refunds have already claimed the rest.'
                            : ' That is more than the receipt collected on that line.',
                    ));
                }

                $lines[] = $this->lineFor($detail, $want, $restoreDues);
                $total = round($total + $want, 2);
            }

            // A line the caller named that is not on this receipt. Dropping it
            // silently is the same harm again — the total refunded would be less
            // than the total asked for, with nothing to say which head vanished.
            foreach ($explicit as $detailId => $amount) {
                if (! isset($placed[$detailId]) && round(max(0, (float) $amount), 2) > 0) {
                    throw new RuntimeException(
                        'One of the lines in this refund does not belong to this receipt. Reopen the receipt and try again.'
                    );
                }
            }

            return [$lines, $total];
        }

        $budget = round(max(0, (float) Arr::get($data, 'amount', 0)), 2);

        $ordered = $details
            ->sortByDesc(fn (FeePaymentDetail $d): float => (float) $d->amount + (float) $d->fine_amount)
            ->values();

        foreach ($ordered as $detail) {
            if ($budget <= 0) {
                break;
            }

            $take = round(min($budget, $this->refundableForDetail($detail)), 2);

            if ($take <= 0) {
                continue;
            }

            $lines[] = $this->lineFor($detail, $take, $restoreDues);
            $budget = round($budget - $take, 2);
            $total = round($total + $take, 2);
        }

        return [$lines, $total];
    }

    /**
     * One refund line built from a receipt line.
     *
     * `restore_dues` is ANDed with "is there an allocation at all": an unallocated
     * advance or a free-hand fee has no debt to re-open, and marking such a line
     * as restoring would be a claim the issue step could not honour.
     *
     * @return array<string, mixed>
     */
    protected function lineFor(FeePaymentDetail $detail, float $amount, bool $restoreDues): array
    {
        return [
            'fee_payment_detail_id' => $detail->id,
            'fee_student_allocation_id' => $detail->fee_student_allocation_id,
            'fee_type_id' => $detail->fee_type_id,
            'label' => $detail->feeType?->name ?? $detail->label,
            'amount' => $amount,
            'restore_dues' => $restoreDues && $detail->fee_student_allocation_id !== null,
        ];
    }

    /**
     * Give the student's dues back for the lines that call for it.
     *
     * SYMMETRIC WITH revert(), and deliberately narrower. revert() walks back
     * `paid_amount`, `discount_amount` and `fine_amount` together because it is
     * undoing the whole receipt line. A partial refund returns MONEY only: the
     * concession that was granted still stands and the fine that was charged was
     * still charged, so only `paid_amount` moves. Floored at zero for the same
     * reason revert() floors it — a figure that has already been walked back by
     * another path must not go negative.
     */
    protected function restoreDues(FeeRefund $refund): void
    {
        // SCOPES LIFTED, like every other money read in this service.
        //
        // This was `$refund->lines()->get()`, the ONE read here that kept them.
        // Under a school/branch mismatch — a console command, a queue job, an API
        // caller, or this same panel after a branch switch — the lines came back
        // EMPTY and the loop below did nothing, while the credit note was still
        // minted, the refund still stamped `issued` and the decision log still
        // recorded "student dues restored". The family's debt stayed settled and
        // the money went out anyway. Reproduced: 0 of 1 lines seen, allocation
        // untouched, CN issued.
        //
        // Soft-delete filtering is deliberately KEPT (only the tenant scopes are
        // named), so an archived line is still ignored.
        $lines = $refund->lines()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->get();

        foreach ($lines as $line) {
            if (! $line->restore_dues || $line->fee_student_allocation_id === null) {
                continue;
            }

            $allocation = FeeStudentAllocation::query()
                ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
                ->lockForUpdate()
                ->find($line->fee_student_allocation_id);

            if ($allocation === null) {
                continue;
            }

            $allocation->paid_amount = round(max(0, (float) $allocation->paid_amount - (float) $line->amount), 2);
            $allocation->syncStatus()->save();
        }
    }

    /**
     * Mint the next credit note number for the branch and stamp the refund issued.
     *
     * {@see FeeCollectionService::nextReceiptSeq()}'s approach — highest used + 1,
     * under `lockForUpdate()`, with the tenant scopes lifted so the series stays
     * contiguous whatever context is active — over a SEPARATE series with its own
     * prefix. A credit note sharing the receipt prefix is the classic way one
     * number ends up meaning two documents.
     *
     * The counter row is what makes the series safe (see
     * {@see nextCreditNoteSeq()}); the unique (branch, credit_note_seq) index —
     * which covers archived rows too, unlike the receipt table's soft-delete-aware
     * key — is the promise the database itself keeps. The write is retried ONCE if
     * that index still fires, because a series this visible should step over a
     * collision rather than 500 in an accountant's face. A second failure is a
     * real one and is rethrown.
     */
    protected function stampCreditNote(FeeRefund $refund, ?User $actor): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $seq = $this->nextCreditNoteSeq((int) $refund->branch_id, (int) $refund->school_id) + $attempt;

            try {
                $refund->forceFill([
                    'status' => 'issued',
                    'issued_by' => $actor?->getAuthIdentifier(),
                    'issued_at' => now(),
                    'credit_note_seq' => $seq,
                    'credit_note_no' => FeeRefunds::creditNotePrefix().$seq,
                ])->save();

                $this->advanceCreditNoteSeq((int) $refund->branch_id, $seq);

                return;
            } catch (QueryException $e) {
                // MySQL 1062 / SQLSTATE 23000 — the unique index did its job. The

                $duplicate = ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';

                if (! $duplicate || $attempt === 1) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The next credit-note sequence for a branch, taken from that branch's counter
     * row in `fee_credit_note_sequences` under a row lock.
     *
     * ── WHY NOT max(credit_note_seq) + 1 ─────────────────────────────────────
     * That is how this started, mirroring what
     * {@see FeeCollectionService::nextReceiptSeq()} used to do — and that method
     * has since been rewritten onto a counter row precisely because max+1 both
     * gapped and collided. Both of its failures apply here verbatim:
     *
     *  • MAX() through an Eloquent model is SOFT-DELETE BLIND. `fee_refunds`
     *    soft-deletes, so a withdrawn credit note vanishes from the aggregate and
     *    its number is handed back out to the next refund.
     *  • `lockForUpdate()` on an AGGREGATE locks the rows the aggregate scanned,
     *    which for a branch that has never issued a credit note is nothing. Two
     *    concurrent first issues both read 0 and both mint 1.
     *
     * A counter row is lockable when the branch has issued nothing, and it is
     * read and advanced inside the same transaction that stamps the refund — so a
     * failed issue does not consume a number and the series stays GAPLESS, which
     * is the whole point of a numbered financial document.
     *
     * `insertOrIgnore` rather than `insert` for the seeding path, and for the same
     * reason the receipt counter uses it: two first-issues on one branch can both
     * arrive here, the unique key on `branch_id` decides which creates the
     * counter, and the loser re-reads the winner's row still under the lock.
     */
    protected function nextCreditNoteSeq(int $branchId, int $schoolId): int
    {
        $row = DB::table('fee_credit_note_sequences')
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('fee_credit_note_sequences')->insertOrIgnore([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'next_seq' => FeeRefunds::creditNoteStart(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('fee_credit_note_sequences')
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        // Belt and braces for a counter sitting UNDER the history it is meant to
        // lead — a restore from a partial dump, a row edited by hand. Read through
        // the QUERY BUILDER, not the model, so no soft-delete scope can hide a
        // number this branch has already issued.
        $highest = (int) DB::table('fee_refunds')
            ->where('branch_id', $branchId)
            ->max('credit_note_seq');

        return max(1, (int) ($row->next_seq ?? 1), $highest + 1, FeeRefunds::creditNoteStart());
    }

    /** Advance a branch's credit-note counter past the number just issued. */
    protected function advanceCreditNoteSeq(int $branchId, int $seq): void
    {
        DB::table('fee_credit_note_sequences')
            ->where('branch_id', $branchId)
            ->update(['next_seq' => $seq + 1, 'updated_at' => now()]);
    }

    /**
     * Write one row to the durable decision log.
     *
     * `fee_refund_decisions` is written inside the same transaction as the
     * transition it describes, so the two commit or roll back together.
     */
    protected function logDecision(FeeRefund $refund, string $decision, ?string $remark, ?int $actorId): void
    {
        FeeRefundDecision::create([
            'school_id' => $refund->school_id,
            'branch_id' => $refund->branch_id,
            'fee_refund_id' => $refund->id,
            'decision' => $decision,
            'remark' => $remark,
            'decided_by' => $actorId ?? auth()->id(),
        ]);
    }

    /** The opening line of the audit trail: what was asked for, and why. */
    protected function requestRemark(FeeRefund $refund): string
    {
        $parts = [
            number_format((float) $refund->amount, 2),
            $refund->reasonLabel(),
            $refund->modeLabel(),
        ];

        if ($refund->reason_detail) {
            $parts[] = $refund->reason_detail;
        }

        $parts[] = (int) $refund->required_approvals === 0
            ? 'no approval required'
            : ((int) $refund->required_approvals).' approval(s) required';

        return mb_substr(implode(' — ', $parts), 0, 1000);
    }

    /** "Approval 1 of 2 — <the approver's own words>". */
    protected function decisionRemark(?string $remark, int $received, int $required): string
    {
        $prefix = 'Approval '.$received.' of '.max($required, $received);
        $remark = $this->trimText($remark);

        return $remark ? $prefix.' — '.$remark : $prefix.'.';
    }

    /** What the issue row records: the document, the mode, the dues decision. */
    protected function issueRemark(FeeRefund $refund): string
    {
        return mb_substr(
            'Issued credit note '.$refund->credit_note_no.' for '.number_format((float) $refund->amount, 2)
                .' via '.$refund->modeLabel()
                .($refund->restore_dues ? '; student dues restored.' : '; dues unchanged (unallocated over-payment).'),
            0,
            1000,
        );
    }

    /** Trim free text, returning null for empty. */
    protected function trimText(mixed $value, int $length = 1000): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }
}
