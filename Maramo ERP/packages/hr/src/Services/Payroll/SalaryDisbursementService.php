<?php

namespace Classivo\Hr\Services\Payroll;

use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrLabels;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SalaryDisbursementService — the bank transfer file for a month's salary, and
 * the pre-flight that has to pass before anyone uploads it.
 *
 * ── WHY THE PRE-FLIGHT IS THE IMPORTANT HALF ─────────────────────────────────
 * The bank columns have been captured on the staff record since the module
 * shipped — `account_title`, `bank_account_no`, `bank_name`, `bank_branch`,
 * `bank_ifsc` — and nothing has ever read them. Nothing has ever CHECKED them
 * either, which is the real problem: a missing IFSC is not discovered when it is
 * typed, it is discovered when a bank rejects a batch of 38 transfers on payday,
 * or worse, accepts 37 and silently drops one.
 *
 * So {@see preflight()} is a report you run BEFORE the file, and it separates two
 * quite different kinds of problem:
 *
 *   BLOCKING — no account number, no IFSC, nothing to pay. The row is left OUT
 *              of the file rather than written half-complete, because a bank
 *              file with a blank account field is how money goes somewhere
 *              nobody intended.
 *   WARNING  — an IFSC that does not look like an IFSC or a SWIFT code, an
 *              account name that had to fall back to the employee's own name,
 *              an account number with odd characters. The row IS written; a
 *              human is told to look at it.
 *
 * ── THE FILE ─────────────────────────────────────────────────────────────────
 * A UTF-8 CSV with a BOM, exactly like {@see a standard Excel-friendly CSV} — the
 * house export format, and one every Indian bank's bulk-transfer template can be
 * mapped from. It is deliberately NOT any one bank's proprietary fixed-width
 * layout: those differ per bank and per corporate agreement, and a wrong guess
 * at one produces a file that looks right and imports wrong. The columns are
 * named so the mapping is obvious.
 *
 * Only GENERATED payrolls are offered. A payroll already marked paid has been
 * disbursed and must not be put in a second file.
 */
class SalaryDisbursementService
{
    /**
     * Indian Financial System Code: four letters, a literal zero, then six
     * alphanumerics. The zero in position five is the part people get wrong.
     */
    public const IFSC_PATTERN = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    /** SWIFT/BIC, which the staff form also accepts in the same field. */
    public const BIC_PATTERN = '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    /** The transfer file's columns, in order. */
    public const HEADINGS = [
        'Employee ID',
        'Employee name',
        'Account title',
        'Account number',
        'IFSC / SWIFT',
        'Bank name',
        'Bank branch',
        'Amount',
        'Currency',
        'Payment reference',
        'Remarks',
    ];

    /**
     * Every payable row for a period, each with whatever is wrong with it.
     *
     * Returns ALL rows including the unpayable ones — the point of a pre-flight
     * is to show the operator the problems, not to quietly drop them and report
     * a smaller total than they were expecting.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     payable: int, blocked: int, warnings: int,
     *     total_amount: float, period: string,
     *     already_paid: int,
     * }
     */
    public function preflight(int $month, int $year, ?int $departmentId = null, ?int $schoolId = null): array
    {
        $schoolId ??= current_school_id();

        $payrolls = $this->payrollsFor($month, $year, $departmentId, $schoolId);

        $rows = [];
        $payable = 0;
        $blocked = 0;
        $warnings = 0;
        $total = 0.0;

        foreach ($payrolls as $payroll) {
            $staff = $payroll->staff;

            // A payroll whose staff record has been archived used to be `continue`d
            // — counted as neither payable nor blocked, absent from the CSV, and
            // absent from this report. Silently omitting somebody from payday is
            // the exact failure the pre-flight exists to catch, so it is now a
            // BLOCKING row that names itself. (The query loads trashed staff, so
            // this only fires for a payroll whose staff row is genuinely gone.)
            if (! $staff instanceof Staff) {
                $rows[] = [
                    'payroll' => $payroll,
                    'staff' => null,
                    'label' => __('hr::messages.disbursement.missing_label', ['id' => $payroll->staff_id]),
                    'amount' => round((float) $payroll->net_salary, 2),
                    'problems' => [[
                        'level' => 'blocking',
                        'message' => __('hr::messages.disbursement.missing_record'),
                    ]],
                    'blocked' => true,
                ];
                $blocked++;

                continue;
            }

            $problems = $this->validate($staff, $payroll);

            if ($staff->trashed()) {
                $problems[] = [
                    'level' => 'blocking',
                    'message' => __('hr::messages.disbursement.archived'),
                ];
            }
            $isBlocked = collect($problems)->contains(fn (array $p): bool => $p['level'] === 'blocking');

            if ($isBlocked) {
                $blocked++;
            } else {
                $payable++;
                $total += (float) $payroll->net_salary;
            }

            if (! $isBlocked && $problems !== []) {
                $warnings++;
            }

            $rows[] = [
                'payroll' => $payroll,
                'staff' => $staff,
                'label' => trim($staff->full_name.' ('.$staff->employee_id.')'),
                'amount' => round((float) $payroll->net_salary, 2),
                'problems' => $problems,
                'blocked' => $isBlocked,
            ];
        }

        return [
            'rows' => $rows,
            'payable' => $payable,
            'blocked' => $blocked,
            'warnings' => $warnings,
            'total_amount' => round($total, 2),
            'period' => HrLabels::monthYear($month, $year),
            'already_paid' => $this->alreadyPaidCount($month, $year, $departmentId, $schoolId),
        ];
    }

    /**
     * Everything wrong with one row.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function validate(Staff $staff, Payroll $payroll): array
    {
        $problems = [];

        $account = trim((string) $staff->bank_account_no);
        $ifsc = strtoupper(trim((string) $staff->bank_ifsc));

        if ($account === '') {
            $problems[] = ['level' => 'blocking', 'message' => __('hr::messages.disbursement.no_account')];
        } elseif (preg_match('/[^A-Za-z0-9]/', $account)) {
            $problems[] = ['level' => 'warning', 'message' => __('hr::messages.disbursement.account_punct')];
        }

        if ($ifsc === '') {
            $problems[] = ['level' => 'blocking', 'message' => __('hr::messages.disbursement.no_ifsc')];
        } elseif (! preg_match(self::IFSC_PATTERN, $ifsc) && ! preg_match(self::BIC_PATTERN, $ifsc)) {
            $problems[] = ['level' => 'warning', 'message' => __('hr::messages.disbursement.invalid_ifsc', ['code' => $ifsc])];
        }

        if (trim((string) $staff->bank_name) === '') {
            $problems[] = ['level' => 'warning', 'message' => __('hr::messages.disbursement.no_bank')];
        }

        if (trim((string) $staff->account_title) === '') {
            $problems[] = ['level' => 'warning', 'message' => __('hr::messages.disbursement.no_title')];
        }

        $net = (float) $payroll->net_salary;

        if ($net <= 0) {
            // Net zero is a real outcome — an employee whose deductions consumed
            // the month's pay — but it is not a transfer, and a zero-value row in
            // a bank file is at best rejected and at worst charged for.
            $problems[] = ['level' => 'blocking', 'message' => __('hr::messages.disbursement.net_zero')];
        }

        return $problems;
    }

    /**
     * The transfer file itself, containing only the rows that passed.
     *
     * Streamed and chunk-free: a school's payroll is tens of rows, not tens of
     * thousands, and holding it is cheaper than the complexity of not.
     */
    public function download(int $month, int $year, ?int $departmentId = null, ?int $schoolId = null): StreamedResponse
    {
        $report = $this->preflight($month, $year, $departmentId, $schoolId);
        $reference = 'SAL'.sprintf('%04d%02d', $year, $month);

        $filename = 'salary-transfer-'.sprintf('%04d-%02d', $year, $month).'.csv';

        return response()->streamDownload(function () use ($report, $reference): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel renders names with accents, for Excel.
            fwrite($handle, "\xEF\xBB\xBF");

            // `escape: ''` is passed deliberately and is not just deprecation
            // housekeeping. PHP's default backslash escaping is not RFC 4180 and
            // is not what a bank's bulk-upload parser expects; a payee name
            // containing a backslash would come out mangled on a file that moves
            // money. Empty escape gives plain quoted CSV, which every such parser
            // reads correctly.
            fputcsv($handle, self::HEADINGS, ',', '"', '');

            foreach ($report['rows'] as $row) {
                if ($row['blocked']) {
                    continue;
                }

                fputcsv($handle, $this->fileRow($row['staff'], $row['payroll'], $reference), ',', '"', '');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One line of the transfer file.
     *
     * The account title falls back to the employee's own name: a blank payee on
     * a transfer is worse than a name the bank might not match, and the
     * pre-flight has already warned about it.
     *
     * @return array<int, string>
     */
    protected function fileRow(Staff $staff, Payroll $payroll, string $reference): array
    {
        return [
            (string) $staff->employee_id,
            $staff->full_name,
            trim((string) $staff->account_title) !== '' ? (string) $staff->account_title : $staff->full_name,
            trim((string) $staff->bank_account_no),
            strtoupper(trim((string) $staff->bank_ifsc)),
            (string) $staff->bank_name,
            (string) $staff->bank_branch,
            number_format((float) $payroll->net_salary, 2, '.', ''),
            Currency::code(),
            $reference.'-'.$staff->employee_id,
            __('hr::messages.disbursement.narration', ['period' => $payroll->period_label]),
        ];
    }

    /**
     * Generated (not yet paid) payrolls for the period.
     *
     * ── THE SCHOOL IS NAMED, NOT ASSUMED ─────────────────────────────────────
     * TenantScope is a documented no-op in an unresolved (console / queue)
     * context, so this query used to return EVERY school's payrolls: a pre-flight
     * run outside a web request listed rows the operator has no business seeing
     * and, worse, the transfer file built from it would have paid them. The same
     * bug that was already fixed on StatutoryRuleSet::resolveFor(). The predicate
     * is spelled out so it fails closed if the middleware is ever dropped.
     *
     * @return Collection<int, Payroll>
     */
    protected function payrollsFor(int $month, int $year, ?int $departmentId, int $schoolId): Collection
    {
        return Payroll::query()
            ->where('school_id', $schoolId)
            ->where('month', $month)
            ->where('year', $year)
            ->where('status', Payroll::STATUS_GENERATED)
            ->when($departmentId, fn ($q) => $q->whereHas('staff', fn ($s) => $s->withTrashed()->where('department_id', $departmentId)))
            // Archived staff are loaded, not skipped: a payroll row exists for
            // them and the operator has to be told, rather than finding out when
            // one person is missing from the batch.
            ->with(['staff' => fn ($q) => $q->withTrashed()])
            ->get()
            ->sortBy(fn (Payroll $p): string => (string) $p->staff?->employee_id)
            ->values();
    }

    /** How many of the period's payrolls have already been disbursed. */
    protected function alreadyPaidCount(int $month, int $year, ?int $departmentId, int $schoolId): int
    {
        return Payroll::query()
            ->where('school_id', $schoolId)
            ->where('month', $month)
            ->where('year', $year)
            ->where('status', Payroll::STATUS_PAID)
            ->when($departmentId, fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('department_id', $departmentId)))
            ->count();
    }
}
