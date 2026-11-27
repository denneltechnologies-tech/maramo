<?php

namespace Classivo\Fees\Services\Reports;

use Carbon\CarbonImmutable;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\Fees\FeeDefaulterQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fee reports as plain rows: every report returns
 * ['columns' => [key => English label], 'rows' => list<array>, 'totals' => array].
 * Money columns are listed in 'money'. Portable SQL only (MySQL, PostgreSQL,
 * SQLite), and every query runs through the tenancy scopes.
 *
 * Filters: from, to (Y-m-d, receipt date), class_id, mode, as_of.
 */
class FeeReportService
{
    public const REPORTS = [
        'collection' => 'Fee collection',
        'mode' => 'Collection by payment mode',
        'class' => 'Collection by class',
        'head_collection' => 'Collection by fee head',
        'head_outstanding' => 'Outstanding by fee head',
        'ageing' => 'Overdue ageing',
        'discounts' => 'Concessions given',
        'reverted' => 'Reverted receipts',
    ];

    /** @return array{columns: array<string, string>, money: list<string>, rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function run(string $report, array $filters = []): array
    {
        return match ($report) {
            'mode' => $this->byMode($filters),
            'class' => $this->byClass($filters),
            'head_collection' => $this->byHead($filters),
            'head_outstanding' => $this->outstandingByHead($filters),
            'ageing' => $this->ageing($filters),
            'discounts' => $this->discounts($filters),
            'reverted' => $this->reverted($filters),
            default => $this->collection($filters),
        };
    }

    protected function payments(array $f, string $status = 'completed'): Builder
    {
        return FeePayment::query()
            ->where('fee_payments.status', $status)
            ->when($f['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('fee_payments.payment_date', '>=', $v))
            ->when($f['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('fee_payments.payment_date', '<=', $v))
            ->when($f['mode'] ?? null, fn (Builder $q, $v) => $q->where('fee_payments.mode', $v))
            ->when($f['class_id'] ?? null, fn (Builder $q, $v) => $q->whereIn('fee_payments.student_id', DB::table('students')->where('class_id', (int) $v)->select('id')));
    }

    protected function collection(array $f): array
    {
        $rows = $this->payments($f)->with('student')->orderBy('payment_date')->orderBy('id')->get()
            ->map(fn (FeePayment $p): array => [
                'receipt_no' => $p->receipt_no,
                'date' => $p->payment_date?->format('d M Y'),
                'student' => $p->student?->full_name ?? '—',
                'mode' => __(FeePayment::MODES[$p->mode] ?? (string) $p->mode),
                'discount' => (float) $p->discount_amount,
                'fine' => (float) $p->fine_amount,
                'paid' => (float) $p->paid_amount,
            ])->all();

        return $this->result(
            ['receipt_no' => 'Receipt no', 'date' => 'Date', 'student' => 'Student', 'mode' => 'Mode', 'discount' => 'Discount', 'fine' => 'Late fine', 'paid' => 'Paid'],
            ['discount', 'fine', 'paid'],
            $rows,
        );
    }

    protected function byMode(array $f): array
    {
        $rows = $this->payments($f)->toBase()
            ->select('mode')
            ->selectRaw('COUNT(*) AS receipts, SUM(paid_amount) AS paid')
            ->groupBy('mode')->orderBy('mode')->get()
            ->map(fn ($r): array => ['mode' => __(FeePayment::MODES[$r->mode] ?? (string) $r->mode), 'receipts' => (int) $r->receipts, 'paid' => (float) $r->paid])
            ->all();

        return $this->result(['mode' => 'Mode', 'receipts' => 'Receipts', 'paid' => 'Paid'], ['paid'], $rows);
    }

    protected function byClass(array $f): array
    {
        $rows = $this->payments($f)->toBase()
            ->join('students', 'students.id', '=', 'fee_payments.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->selectRaw("COALESCE(classes.name, '—') AS class_name, COUNT(*) AS receipts, SUM(fee_payments.paid_amount) AS paid, SUM(fee_payments.discount_amount) AS discount")
            ->groupBy('classes.name')->orderBy('classes.name')->get()
            ->map(fn ($r): array => ['class' => $r->class_name, 'receipts' => (int) $r->receipts, 'discount' => (float) $r->discount, 'paid' => (float) $r->paid])
            ->all();

        return $this->result(['class' => 'Class', 'receipts' => 'Receipts', 'discount' => 'Discount', 'paid' => 'Paid'], ['discount', 'paid'], $rows);
    }

    protected function byHead(array $f): array
    {
        $rows = $this->payments($f)->toBase()
            ->join('fee_payment_details', 'fee_payment_details.fee_payment_id', '=', 'fee_payments.id')
            ->leftJoin('fee_types', 'fee_types.id', '=', 'fee_payment_details.fee_type_id')
            ->whereNull('fee_payment_details.deleted_at')
            ->selectRaw("COALESCE(fee_types.name, fee_payment_details.label, '—') AS head, SUM(fee_payment_details.amount) AS amount, SUM(fee_payment_details.fine_amount) AS fine, SUM(fee_payment_details.discount_amount) AS discount")
            ->groupByRaw("COALESCE(fee_types.name, fee_payment_details.label, '—')")
            ->orderBy('head')->get()
            ->map(fn ($r): array => ['head' => $r->head, 'discount' => (float) $r->discount, 'fine' => (float) $r->fine, 'collected' => (float) $r->amount + (float) $r->fine])
            ->all();

        return $this->result(['head' => 'Fee head', 'discount' => 'Discount', 'fine' => 'Late fine', 'collected' => 'Collected'], ['discount', 'fine', 'collected'], $rows);
    }

    protected function outstandingByHead(array $f): array
    {
        $balance = app(FeeDefaulterQuery::class)->balanceExpression();

        $rows = FeeStudentAllocation::query()
            ->when($f['class_id'] ?? null, fn (Builder $q, $v) => $q->whereIn('fee_student_allocations.student_id', DB::table('students')->where('class_id', (int) $v)->select('id')))
            ->toBase()
            ->join('fee_masters', 'fee_masters.id', '=', 'fee_student_allocations.fee_master_id')
            ->join('fee_types', 'fee_types.id', '=', 'fee_masters.fee_type_id')
            ->whereRaw("{$balance} > 0")
            ->selectRaw("fee_types.name AS head, COUNT(DISTINCT fee_student_allocations.student_id) AS students, SUM({$balance}) AS outstanding")
            ->groupBy('fee_types.name')->orderBy('fee_types.name')->get()
            ->map(fn ($r): array => ['head' => $r->head, 'students' => (int) $r->students, 'outstanding' => (float) $r->outstanding])
            ->all();

        return $this->result(['head' => 'Fee head', 'students' => 'Students', 'outstanding' => 'Outstanding'], ['outstanding'], $rows);
    }

    protected function ageing(array $f): array
    {
        $asOf = CarbonImmutable::parse($f['as_of'] ?? now()->toDateString())->startOfDay();
        $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];
        $counts = array_fill_keys(array_keys($buckets), 0);

        FeeStudentAllocation::query()
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->when($f['class_id'] ?? null, fn (Builder $q, $v) => $q->whereIn('student_id', DB::table('students')->where('class_id', (int) $v)->select('id')))
            ->get()
            ->each(function (FeeStudentAllocation $a) use ($asOf, &$buckets, &$counts): void {
                $balance = $a->balance();

                if ($balance <= 0) {
                    return;
                }

                $days = (int) $a->due_date->copy()->startOfDay()->diffInDays($asOf);
                $key = match (true) {
                    $days <= 30 => '0_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => '90_plus',
                };
                $buckets[$key] += $balance;
                $counts[$key]++;
            });

        $labels = ['0_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', '90_plus' => 'Over 90 days'];
        $rows = [];

        foreach ($buckets as $key => $amount) {
            $rows[] = ['bucket' => __($labels[$key]), 'lines' => $counts[$key], 'outstanding' => round($amount, 2)];
        }

        return $this->result(['bucket' => 'Overdue by', 'lines' => 'Fee lines', 'outstanding' => 'Outstanding'], ['outstanding'], $rows);
    }

    protected function discounts(array $f): array
    {
        $rows = $this->payments($f)
            ->join('fee_payment_details', 'fee_payment_details.fee_payment_id', '=', 'fee_payments.id')
            ->leftJoin('fee_types', 'fee_types.id', '=', 'fee_payment_details.fee_type_id')
            ->where('fee_payment_details.discount_amount', '>', 0)
            ->with('student')
            ->orderBy('fee_payments.payment_date')
            ->get(['fee_payments.*', 'fee_types.name as head_name', 'fee_payment_details.discount_amount as line_discount', 'fee_payment_details.discount_reason as line_reason'])
            ->map(fn (FeePayment $p): array => [
                'receipt_no' => $p->receipt_no,
                'date' => $p->payment_date?->format('d M Y'),
                'student' => $p->student?->full_name ?? '—',
                'head' => $p->head_name ?? '—',
                'reason' => $p->line_reason ?? '—',
                'discount' => (float) $p->line_discount,
            ])->all();

        return $this->result(['receipt_no' => 'Receipt no', 'date' => 'Date', 'student' => 'Student', 'head' => 'Fee head', 'reason' => 'Reason', 'discount' => 'Discount'], ['discount'], $rows);
    }

    protected function reverted(array $f): array
    {
        $rows = $this->payments($f, 'reverted')->with('student')->orderBy('payment_date')->get()
            ->map(fn (FeePayment $p): array => [
                'receipt_no' => $p->receipt_no,
                'date' => $p->payment_date?->format('d M Y'),
                'student' => $p->student?->full_name ?? '—',
                'reverted_at' => $p->reverted_at?->format('d M Y'),
                'reason' => $p->revert_reason ?? '—',
                'paid' => (float) $p->paid_amount,
            ])->all();

        return $this->result(['receipt_no' => 'Receipt no', 'date' => 'Date', 'student' => 'Student', 'reverted_at' => 'Reverted on', 'reason' => 'Reason', 'paid' => 'Amount'], ['paid'], $rows);
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $money
     * @param  list<array<string, mixed>>  $rows
     */
    protected function result(array $columns, array $money, array $rows): array
    {
        $totals = [];

        foreach ($money as $key) {
            $totals[$key] = round(array_sum(array_map(fn (array $r): float => (float) ($r[$key] ?? 0), $rows)), 2);
        }

        return ['columns' => $columns, 'money' => $money, 'rows' => $rows, 'totals' => $totals];
    }
}
