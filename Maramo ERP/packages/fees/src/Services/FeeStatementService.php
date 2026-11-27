<?php

namespace Classivo\Fees\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeeCarryForward;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * FeeStatementService — the ONE source of truth for the cross-session fee
 * statement (session-filtered receipts, month-wise installments including
 * FUTURE months, per-month bifurcation and the statement-style receipt PDF).
 * Panels and any API layer a host app adds should read through here, so every
 * surface shows identical figures.
 *
 * All reads deliberately bypass the {@see SessionScope} current-session pin and
 * take an explicit session id instead (null = every session).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CARRIED-FORWARD BALANCES NEED NO SPECIAL CASE HERE — ON PURPOSE
 * ─────────────────────────────────────────────────────────────────────────────
 * An opening balance brought in from an earlier session IS a
 * {@see FeeStudentAllocation} — {@see FeeCarryForwardService} raises it as one,
 * under a per-branch "Previous Session Dues" head — so every method below already
 * statements it, bifurcates it and prints it on a receipt with no code of its own.
 * That is why this service has no reference to `fee_carry_forwards`: it does not
 * need one, and adding a second kind of debt for it to understand would be exactly
 * the parallel path that left those rows unreadable in the first place.
 *
 * The ONE thing that does need saying is below: carrying a balance forward leaves
 * the source session's lines open (they carry receipts and history), so the same
 * rupees exist in two sessions at once. Per-session reads — which is every screen
 * and every API endpoint — are unaffected. An ALL-SESSIONS total is not, and
 * {@see carriedOutOf()} is the figure it must subtract.
 */
class FeeStatementService
{
    // ------------------------------------------------------------- Sessions

    /**
     * Academic sessions that carry any fee data (allocation OR receipt) for the
     * student — for session filters. Newest first. Shape (per row):
     * ['id', 'name', 'is_current', 'receipt_count'].
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sessionsForStudent(int $studentId): Collection
    {
        $allocationSessionIds = FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $studentId)
            ->distinct()
            ->pluck('session_id');

        $receiptCounts = FeePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $studentId)
            ->selectRaw('session_id, COUNT(*) AS receipts')
            ->groupBy('session_id')
            ->pluck('receipts', 'session_id');

        return AcademicSession::query()
            ->whereIn('id', $allocationSessionIds->merge($receiptCounts->keys())->filter()->unique()->values())
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicSession $session): array => [
                'id' => $session->id,
                'name' => $session->name,
                'is_current' => (bool) $session->is_current,
                'receipt_count' => (int) ($receiptCounts[$session->id] ?? 0),
            ])
            ->values();
    }

    /** The display name of one academic session (spanning the tenant scope only). */
    public function sessionName(?int $sessionId): ?string
    {
        return $sessionId === null
            ? null
            : AcademicSession::query()->find($sessionId)?->name;
    }

    // ---------------------------------------------------------- Allocations

    /**
     * A student's allocations for one session (bypassing the current-session pin),
     * with fee master + type eager-loaded, ordered by due date.
     *
     * @return Collection<int, FeeStudentAllocation>
     */
    public function sessionAllocations(int $studentId, ?int $sessionId): Collection
    {
        return FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $studentId)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->with('feeMaster.feeType')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    // --------------------------------------------------------- Installments

    /**
     * All allocations of a session grouped by due month — INCLUDING future
     * months — as an API client returns them (GET /api/v1/fees/installments).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function installments(int $studentId, ?int $sessionId): Collection
    {
        return $this->sessionAllocations($studentId, $sessionId)
            ->groupBy(fn (FeeStudentAllocation $a): string => $a->due_date?->format('Y-m') ?? '0000-00')
            ->sortKeys()
            ->map(fn (Collection $group, string $key): array => $this->monthPayload($key, $group))
            ->values();
    }

    /**
     * The fee bifurcation for one due month (`YYYY-MM`): every allocation line
     * with its head/type/amounts plus a summary grouped by fee-type name
     * (tuition / transport / … — derived, not hardcoded). Keys: month, label,
     * lines, summary — exactly the API's installmentDetail payload tail.
     *
     * @return array<string, mixed>
     */
    public function installmentDetail(int $studentId, ?int $sessionId, string $month): array
    {
        $allocations = $this->sessionAllocations($studentId, $sessionId)
            ->filter(fn (FeeStudentAllocation $a): bool => $a->due_date?->format('Y-m') === $month)
            ->values();

        return ['month' => $month, 'label' => $this->monthLabel($month)]
            + $this->bifurcation($allocations);
    }

    /**
     * Month-wise statement for the WEB panels: every month row of the API's
     * installments payload PLUS the bifurcation (detail lines + type-bucket
     * summary) embedded per month — one query for the whole statement, so the
     * admin tab and portal pages can expand a month without extra round trips.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function monthlyStatement(int $studentId, ?int $sessionId): Collection
    {
        return $this->sessionAllocations($studentId, $sessionId)
            ->groupBy(fn (FeeStudentAllocation $a): string => $a->due_date?->format('Y-m') ?? '0000-00')
            ->sortKeys()
            ->map(fn (Collection $group, string $key): array => $this->monthPayload($key, $group)
                + $this->bifurcation($group->values()))
            ->values();
    }

    /**
     * One grouped installment row (the API's month shape).
     *
     * @param  Collection<int, FeeStudentAllocation>  $group
     * @return array<string, mixed>
     */
    public function monthPayload(string $key, Collection $group): array
    {
        $total = $paid = $discount = $fine = $balance = 0.0;
        $minDue = null;

        $rows = $group->map(function (FeeStudentAllocation $a) use (&$total, &$paid, &$discount, &$fine, &$balance, &$minDue): array {
            $total += (float) $a->amount;
            $paid += (float) $a->paid_amount;
            $discount += (float) $a->discount_amount;
            $fine += (float) $a->fine_amount;
            $balance += $a->balance();

            if ($a->due_date !== null && ($minDue === null || $a->due_date->lt($minDue))) {
                $minDue = $a->due_date;
            }

            return [
                'id' => $a->id,
                'head' => $a->feeMaster?->feeType?->name ?? 'Fee',
                'type' => $a->feeMaster?->feeType?->code,
                'amount' => (float) $a->amount,
                'paid' => (float) $a->paid_amount,
                'balance' => $a->balance(),
                'due_date' => optional($a->due_date)->toDateString(),
            ];
        })->values();

        return [
            'month' => $key,
            'label' => $this->monthLabel($key),
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'discount' => round($discount, 2),
            'fine' => round($fine, 2),
            'balance' => round($balance, 2),
            'status' => $this->monthStatus($balance, $paid, $minDue),
            'allocations' => $rows,
        ];
    }

    /** "September 2026" for "2026-09"; "Unscheduled" for the null-due bucket. */
    public function monthLabel(string $key): string
    {
        return $key === '0000-00'
            ? 'Unscheduled'
            : Carbon::createFromFormat('Y-m-d', $key.'-01')->format('F Y');
    }

    /**
     * Bifurcation of a set of allocation lines: ['lines' => …, 'summary' => …]
     * where summary carries the total, one bucket per fee-type slug, then
     * discount / fine / paid / balance (the API's installmentDetail tail).
     *
     * @param  Collection<int, FeeStudentAllocation>  $allocations
     * @return array{lines: Collection<int, array<string, mixed>>, summary: array<string, float>}
     */
    public function bifurcation(Collection $allocations): array
    {
        $summary = ['total' => 0.0];
        $tail = ['discount' => 0.0, 'fine' => 0.0, 'paid' => 0.0, 'balance' => 0.0];

        $lines = $allocations->map(function (FeeStudentAllocation $a) use (&$summary, &$tail): array {
            $typeName = $a->feeMaster?->feeType?->name ?? 'Others';
            $bucket = Str::slug(preg_replace('/\s+fees?\s*$/i', '', $typeName), '_') ?: 'others';

            $summary['total'] = round($summary['total'] + (float) $a->amount, 2);
            $summary[$bucket] = round(($summary[$bucket] ?? 0.0) + (float) $a->amount, 2);
            $tail['discount'] = round($tail['discount'] + (float) $a->discount_amount, 2);
            $tail['fine'] = round($tail['fine'] + (float) $a->fine_amount, 2);
            $tail['paid'] = round($tail['paid'] + (float) $a->paid_amount, 2);
            $tail['balance'] = round($tail['balance'] + $a->balance(), 2);

            return [
                'allocation_id' => $a->id,
                'head' => $typeName,
                'type_name' => $typeName,
                'amount' => (float) $a->amount,
                'discount' => (float) $a->discount_amount,
                'fine' => (float) $a->fine_amount,
                'paid' => (float) $a->paid_amount,
                'balance' => $a->balance(),
                'due_date' => optional($a->due_date)->toDateString(),
                'status' => $this->allocationDerivedStatus($a),
            ];
        })->values();

        return [
            'lines' => $lines,
            'summary' => $summary + $tail,
        ];
    }

    // -------------------------------------------------------------- Statuses

    /** paid | partial | due | upcoming for a grouped month. */
    public function monthStatus(float $balance, float $paid, ?CarbonInterface $minDue): string
    {
        if ($balance <= 0.001) {
            return 'paid';
        }

        if ($paid > 0.001) {
            return 'partial';
        }

        return $minDue !== null && $minDue->isFuture() ? 'upcoming' : 'due';
    }

    /** paid | partial | due | upcoming for a single allocation (upcoming = future due, unpaid). */
    public function allocationDerivedStatus(FeeStudentAllocation $a): string
    {
        if ($a->balance() <= 0.001) {
            return 'paid';
        }

        if ((float) $a->paid_amount > 0.001) {
            return 'partial';
        }

        return $a->due_date !== null && $a->due_date->isFuture() ? 'upcoming' : 'due';
    }

    // ------------------------------------------------------ Carried balances

    /**
     * The opening balances carried INTO a session for a student — the register
     * rows, each with the live outstanding figure of the debt it raised.
     *
     * For a "where did this line come from?" panel beside the statement: the
     * allocation says how much is owed, this says which year it came from, who
     * carried it and what they wrote on it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function carriedIn(int $studentId, int $sessionId): Collection
    {
        return FeeCarryForward::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $studentId)
            ->where('session_id', $sessionId)
            ->with('fromSession:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (FeeCarryForward $row): array => [
                'id' => (int) $row->id,
                'from_session' => $row->fromSession?->name,
                'from_session_id' => $row->from_session_id !== null ? (int) $row->from_session_id : null,
                'allocation_id' => $row->fee_student_allocation_id !== null ? (int) $row->fee_student_allocation_id : null,
                'carried' => (float) $row->amount,
                'outstanding' => $row->outstanding(),
                'status' => $row->derivedStatus(),
                'note' => $row->note,
            ]);
    }

    /**
     * How much of a session's balance has already been carried OUT of it — i.e. is
     * payable in a LATER session and must not be counted twice in a cross-session
     * total.
     *
     * A carry-forward deliberately leaves the source lines open (they carry the
     * receipts, discounts and fines that past-session statements and old receipt
     * PDFs render, and fabricating a settlement on them would be inventing money).
     * The consequence is that a total spanning every session sees the same rupees
     * as an open source line AND as an opening balance. Every per-session read is
     * safe; a caller that sums across sessions subtracts this.
     */
    public function carriedOutOf(int $studentId, int $sessionId): float
    {
        return app(FeeCarryForwardService::class)->carriedOutOf($sessionId, $studentId);
    }

    // -------------------------------------------------------------- Receipts

    /**
     * A student's (non-reverted) receipts for one session, newest first —
     * bypassing the current-session pin so old-session receipts stay reachable.
     * Null session = every session.
     *
     * @return Collection<int, FeePayment>
     */
    public function receipts(int $studentId, ?int $sessionId): Collection
    {
        return FeePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $studentId)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->where('status', '!=', 'reverted')
            ->with('session:id,name')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find a receipt by id across ALL sessions, with the relations the app-style
     * PDF needs. Null when it does not exist.
     */
    public function findReceipt(int $paymentId): ?FeePayment
    {
        return FeePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->with(['student', 'details.feeType', 'collector', 'session'])
            ->find($paymentId);
    }

    /**
     * The app-style receipt PDF (resources/views/pdf/fee-receipt-app.blade.php,
     * dompdf, A4) for a receipt of ANY session — the exact document an API client
     * API's GET /fees/receipt/{id}/pdf streams.
     */
    public function receiptPdf(FeePayment $payment): DomPdf
    {
        $branch = Branch::withoutGlobalScopes()->find($payment->branch_id);

        return Pdf::loadView('classivo-fees::pdf.fee-receipt-app', [
            'payment' => $payment,
            'student' => $payment->student,
            'school' => School::find($payment->school_id),
            'branch' => $branch,
            'sessionName' => $payment->session?->name,
            'currencySymbol' => Money::symbol($branch),
            'amountInWords' => $this->amountInWords((float) $payment->paid_amount),
        ])->setPaper('a4');
    }

    /**
     * "Two thousand eight hundred rupees only" — null when intl is unavailable.
     * Unit words come from config `fees.amount_words.<CODE>` (major/minor),
     * defaulting to rupees/paise for INR and "<code>/cents" otherwise.
     */
    public function amountInWords(float $amount): ?string
    {
        if (! class_exists(\NumberFormatter::class)) {
            return null;
        }

        $code = Money::code();
        $default = $code === 'INR'
            ? ['major' => 'rupees', 'minor' => 'paise']
            : ['major' => strtolower($code), 'minor' => 'cents'];
        $words = (array) config('fees.amount_words.'.$code, $default);

        $formatter = new \NumberFormatter($code === 'INR' ? 'en_IN' : 'en', \NumberFormatter::SPELLOUT);
        $major = (int) floor($amount);
        $minor = (int) round(($amount - $major) * 100);

        $text = ucfirst((string) $formatter->format($major)).' '.($words['major'] ?? '');

        if ($minor > 0) {
            $text .= ' and '.$formatter->format($minor).' '.($words['minor'] ?? '');
        }

        return $text.' only';
    }
}
