<?php

namespace Classivo\Hr\Services\Payroll;

use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffSalaryStructure;
use Classivo\Hr\Services\PayrollService;
use Illuminate\Support\Collection;

/**
 * SalaryStructureResolver — "what was this employee's salary structure DURING
 * this month", turned into a list of priced lines.
 *
 * Everything that used to be a free-text `{name, amount}` pair on the staff
 * record now arrives here as a {@see SalaryComponent} plus the employee's
 * {@see StaffSalaryStructure} row for it, and this class turns that pair into a
 * rupee figure — resolving percentages against other heads on the way.
 *
 * ── THE PERIOD, NOT TODAY ────────────────────────────────────────────────────
 * The structure is resolved as at the LAST DAY OF THE PAYROLL MONTH, never as at
 * now(). That single choice is what stops a raise leaking backwards into a month
 * already paid: regenerating March in June resolves March's rows and produces
 * March's numbers. Reading `staff.allowances` — one mutable blob with no
 * history — could never do that, which is why an already-issued payslip used to
 * be able to change underneath the person holding it.
 *
 * ── PERCENTAGES, AND WHY THEY TAKE THREE PASSES ──────────────────────────────
 * A percentage head names other heads as its base: HRA is 40% of BASIC + DA. DA
 * may itself be a percentage of BASIC, so bases chain. Rather than sort a
 * dependency graph — and rather than pretend cycles cannot be typed into a form
 * — the resolver simply sweeps the list repeatedly, pricing whatever has become
 * computable, and stops when a sweep adds nothing. Three sweeps resolve any
 * chain a school will realistically build; a genuine cycle (A% of B, B% of A)
 * settles at zero for both rather than looping, and shows up as a zero line
 * somebody can see and fix.
 *
 * ── THE LEGACY MERGE, WHICH IS PER HEAD ──────────────────────────────────────
 * The old JSON columns are still read, but head by head rather than all or
 * nothing: any `{name, amount}` entry that the resolved structure has not
 * replaced is still paid, priced exactly as payroll always priced it. Half
 * migrated is the normal state of that table while a school moves across one
 * head at a time, and the alternative — "has any structure row, therefore
 * ignore the JSON" — silently stops paying every head that has not moved yet.
 * See {@see uncoveredLegacyLines()} for the matching rule and for the one thing
 * this cannot fix: the JSON has no history, so a period priced from it can still
 * be restated by an edit to the staff record. The payroll lock is the answer to
 * that, which is why issuing a payslip locks it.
 */
class SalaryStructureResolver
{
    /** Sweeps allowed while resolving chained percentage bases. */
    private const RESOLUTION_PASSES = 3;

    public function __construct(
        private readonly SalaryComponentProvisioner $provisioner,
    ) {}

    /**
     * The employee's priced structure for a period.
     *
     * Returns ONLY the heads whose value is known from the structure itself —
     * basic, fixed amounts and percentages. Statutory and system heads (PF, LOP,
     * advance recovery) are deliberately absent: they are functions of the
     * numbers this method produces, so {@see PayrollService}
     * computes them afterwards from this output.
     *
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     values: array<string, float>,
     *     basic: float,
     *     source: string,
     * }
     */
    public function resolve(Staff $staff, int $month, int $year): array
    {
        $periodEnd = self::periodEnd($month, $year);

        $components = $this->componentsFor($staff);
        $structures = $this->structuresInForce($staff, $periodEnd);

        $lines = [];
        $pending = [];
        $notes = [];

        // ── BASIC, EFFECTIVE-DATED LIKE EVERYTHING ELSE ─────────────────────
        //
        // This used to be `(float) $staff->basic_salary` and nothing else, which
        // left the LARGEST head — 62% of this payroll — as a single mutable
        // number with no history, inside a class whose entire premise is that a
        // period resolves the salary that applied DURING it. A raise typed on
        // the staff master retroactively restated every month not yet locked:
        // an unlocked September moved from ₹71,360 to ₹81,360 after the fact,
        // and the docblock above promised the opposite.
        //
        // A BASIC structure row now wins where one exists, exactly as it does
        // for every other head, so a raise is a new row and March keeps paying
        // March's basic. Where none exists the staff master is still the source
        // — every school starts there and nobody's pay may move because this
        // shipped — but the payroll SAYS SO in its notes instead of reporting
        // `structure` and leaving the exposure invisible.
        $basicComponent = $this->basicComponent($staff);
        $basicRow = $basicComponent === null ? null : $structures->get($basicComponent->id);
        $basicFromStructure = $basicRow !== null;
        $basic = $basicFromStructure ? $basicRow->fixedAmount() : (float) $staff->basic_salary;

        if (! $basicFromStructure) {
            $notes[] = __('hr::messages.calc.basic_no_history');
        }

        // BASIC is seeded BEFORE the loop and unconditionally.
        //
        // It is the base almost every percentage head names, and it is paid from
        // `staff.basic_salary` whether or not a BASIC component row exists or is
        // active. Deriving it inside the loop meant that DEACTIVATING the BASIC
        // head — a plausible thing for an admin to try — silently dropped it out
        // of `$values`, so every percentage allowance resolved its base to
        // nothing and paid ₹0 while basic itself still paid in full.
        $values = [SalaryComponent::CODE_BASIC => $basic];

        foreach ($components as $component) {
            $structure = $structures->get($component->id);

            if ($component->isBaseSalary()) {
                // $basic is already resolved above — from this employee's dated
                // BASIC row where one exists, otherwise from the staff master.
                // Re-reading `staff.basic_salary` here is what made the dating
                // unreachable for the one head that matters most.
                $values[$component->code] = $basic;

                // BASIC is priced so percentages can name it, but it emits no
                // payslip line — `payrolls.basic_salary` already carries it and
                // the template prints that column itself. Emitting one here would
                // pay basic twice.
                if ($component->emit_as_line) {
                    $lines[] = $this->line($component, $basic, 'structure');
                }

                continue;
            }

            // Statutory and system heads are priced later, by the engine.
            if ($component->calculation === 'statutory' || $component->calculation === 'system') {
                continue;
            }

            if ($structure === null) {
                continue;
            }

            if ($component->calculation === 'fixed') {
                $amount = $structure->fixedAmount();
                $values[$component->code] = $amount;
                $lines[] = $this->line($component, $amount, 'structure', $structure);

                continue;
            }

            // percentage — needs its base priced first, so defer it.
            $pending[] = ['component' => $component, 'structure' => $structure];
        }

        // Sweep for percentage heads until nothing more becomes computable.
        for ($pass = 0; $pass < self::RESOLUTION_PASSES && $pending !== []; $pass++) {
            $stillPending = [];
            $resolvedThisPass = 0;

            foreach ($pending as $entry) {
                $component = $entry['component'];
                $base = $this->baseValue($component, $values);

                if ($base === null) {
                    $stillPending[] = $entry;

                    continue;
                }

                $rate = (float) ($entry['structure']->percentage ?? $component->percentage ?? 0);
                $amount = round($base * $rate / 100, 2);

                $values[$component->code] = $amount;
                $lines[] = $this->line($component, $amount, 'structure', $entry['structure']);
                $resolvedThisPass++;
            }

            $pending = $stillPending;

            if ($resolvedThisPass === 0) {
                break;
            }
        }

        // Anything still unresolved names a base that does not exist or forms a
        // cycle. It is emitted at zero rather than dropped, so the gap is visible
        // on the payslip instead of quietly shrinking somebody's pay.
        foreach ($pending as $entry) {
            $values[$entry['component']->code] = 0.0;
            $lines[] = $this->line($entry['component'], 0.0, 'structure', $entry['structure']);
        }

        // ── The legacy heads this employee has NOT been migrated off ─────────
        //
        // This used to be all-or-nothing: any structure row at all and the JSON
        // columns were ignored entirely. Half-migrated is the NORMAL state of
        // that table — a school moves one head at a time — and the old rule meant
        // migrating HRA silently stopped paying Conveyance, with no line and no
        // warning. The merge is per HEAD, so a legacy entry is paid until the
        // structure genuinely replaces it.
        $legacy = $this->uncoveredLegacyLines($staff, $lines, $periodEnd);

        if ($legacy !== []) {
            $lines = array_merge($lines, $legacy);
            $notes[] = count($legacy).' head(s) still priced from the old free-text salary structure.';
        }

        usort($lines, static fn (array $a, array $b): int => [$a['sequence'], $a['code']] <=> [$b['sequence'], $b['code']]);

        $fromStructure = array_filter($lines, static fn (array $l): bool => $l['source'] === 'structure');

        return [
            'lines' => $lines,
            'values' => $values,
            'basic' => $basic,
            'basic_source' => $basicFromStructure ? 'structure' : 'staff_record',
            'source' => match (true) {
                $legacy !== [] && $fromStructure !== [] => 'mixed',
                $legacy !== [] => 'legacy',
                default => 'structure',
            },
            'notes' => $notes,
        ];
    }

    /**
     * Structure rows in force at $date, keyed by component id.
     *
     * When an employee somehow has two overlapping rows for one head — closing
     * the old row is a manual step and someone will forget it — the one that
     * STARTED LATEST wins. That is the row a human would call current, and
     * silently summing the two would pay the head twice.
     *
     * The global scopes are dropped and the employee named directly. `staff_id`
     * already pins the row to one person of one school and one campus, so there
     * is nothing for a tenant scope to protect — while LEAVING them on meant a
     * row whose `branch_id` did not match the operator's currently-selected
     * campus was invisible in the panel and visible in the console, so the same
     * employee priced differently depending on where payroll was run. A salary
     * head must not disappear because of who is looking.
     *
     * @return Collection<int, StaffSalaryStructure>
     */
    protected function structuresInForce(Staff $staff, string $date): Collection
    {
        return StaffSalaryStructure::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('staff_id', $staff->id)
            ->inForce($date)
            ->with('component')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // keyBy keeps the LAST occurrence, and the ordering above puts the
            // latest-starting row last — so the newest wins without a subquery.
            ->keyBy('salary_component_id');
    }

    /**
     * Active components for this employee's branch, in display order.
     *
     * @return Collection<int, SalaryComponent>
     */
    public function componentsFor(Staff $staff): Collection
    {
        // A branch created after the head master shipped — by the Branches screen
        // or by the SaaS provisioner — would otherwise have no system heads at
        // all. Provisioning lazily here means no tenant can end up without them
        // because somebody forgot to wire a creation path up. Memoised per branch,
        // so this is one count query per generation run, not one per employee.
        $this->provisioner->ensureFor((int) $staff->school_id, (int) $staff->branch_id);

        return SalaryComponent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $staff->school_id)
            ->where('branch_id', $staff->branch_id)
            ->whereNull('deleted_at')
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * The BASIC head for this employee's branch — INCLUDING a deactivated or
     * soft-deleted one.
     *
     * ── WHY TRASHED AND INACTIVE ROWS STILL COUNT ────────────────────────────
     * Basic salary is paid from `staff.basic_salary` (or a dated BASIC row)
     * whether or not the head exists, but the HEAD is where its flags live:
     * whether it is PF wages, whether it is ESI wages, whether it prorates.
     * {@see componentByCode()} filters deleted rows out, so soft-deleting the
     * BASIC head — a plausible tidy-up, since it prints no payslip line —
     * returned null and the engine lost those flags entirely: the PF wage base
     * fell from ₹32,000 to ₹0 (provident fund silently stopped) and the ESI base
     * from ₹52,800 to ₹20,800, which is UNDER the ₹21,000 threshold, so ESI
     * silently STARTED deducting from somebody earning ₹65,600. Basic was still
     * being paid the whole time; only the bases it belongs to forgot it.
     *
     * A deleted head's flags are still the school's last stated answer about
     * basic pay, so they are honoured. Only when no BASIC row has ever existed
     * does the engine fall back to its own defaults — see
     * {@see PayrollService::wageBase()}. This is the same failure
     * the percentage path had (deactivating BASIC zeroed every percentage head)
     * and the same fix, applied to the statutory path it was left live on.
     */
    public function basicComponent(Staff $staff): ?SalaryComponent
    {
        $this->provisioner->ensureFor((int) $staff->school_id, (int) $staff->branch_id);

        return SalaryComponent::query()
            // withoutGlobalScopes() drops the soft-delete scope along with the
            // tenant ones, which is the point: a deleted BASIC head still tells
            // us how basic pay was flagged.
            ->withoutGlobalScopes()
            ->where('school_id', $staff->school_id)
            ->where('branch_id', $staff->branch_id)
            ->where('code', SalaryComponent::CODE_BASIC)
            // A live head beats a deleted one if a school somehow has both.
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderByDesc('status')
            ->first();
    }

    /**
     * The branch's hand-entered heads that stand in for a statutory calculation,
     * as lookup maps the engine can test a line against.
     *
     * Returns `['codes' => [CODE => kind], 'names' => [lowercased name => kind]]`.
     * Both are needed because a line can arrive from either half of the salary
     * model: a STRUCTURE line names a component (and carries the flag directly),
     * while a LEGACY JSON line is free text with no component behind it — and it
     * is exactly the schools still on that free text that carry a hand-worked
     * "Provident Fund" head, so leaving them out would miss the common case.
     *
     * Inactive and soft-deleted heads are included. A head that has been switched
     * off can still be the one a legacy JSON entry normalises to, and forgetting
     * that it means provident fund would put the double deduction back.
     *
     * @return array{codes: array<string, string>, names: array<string, string>}
     */
    public function supersessions(Staff $staff): array
    {
        $components = SalaryComponent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $staff->school_id)
            ->where('branch_id', $staff->branch_id)
            ->whereNotNull('supersedes_statutory_kind')
            ->get();

        $codes = [];
        $names = [];

        foreach ($components as $component) {
            $kind = $component->supersededStatutoryKind();

            if ($kind === null) {
                continue;
            }

            $codes[(string) $component->code] = $kind;
            $names[strtolower(trim((string) $component->name))] = $kind;
        }

        return ['codes' => $codes, 'names' => $names];
    }

    /** One component of this employee's branch by code, or null. */
    public function componentByCode(Staff $staff, string $code): ?SalaryComponent
    {
        $this->provisioner->ensureFor((int) $staff->school_id, (int) $staff->branch_id);

        return SalaryComponent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $staff->school_id)
            ->where('branch_id', $staff->branch_id)
            ->whereNull('deleted_at')
            ->where('code', $code)
            ->first();
    }

    /**
     * The summed value of a percentage head's base codes, or null when one of
     * them has not been priced yet (so the caller can defer and sweep again).
     *
     * A head with no base codes at all is treated as a percentage OF BASIC —
     * that is what somebody means when they type "40%" into an HRA row and never
     * touch the base field, and refusing to guess would just produce a zero.
     */
    protected function baseValue(SalaryComponent $component, array $values): ?float
    {
        $codes = $component->baseCodes();

        if ($codes === []) {
            $codes = [SalaryComponent::CODE_BASIC];
        }

        $total = 0.0;

        foreach ($codes as $code) {
            if (! array_key_exists($code, $values)) {
                return null;
            }

            $total += $values[$code];
        }

        return $total;
    }

    /**
     * The legacy free-text heads that this employee's structure has NOT replaced.
     *
     * ── WHY THIS IS PER HEAD AND NOT ALL-OR-NOTHING ──────────────────────────
     * A staff record can carry both: some heads migrated into
     * `staff_salary_structures`, the rest still sitting in the JSON columns. That
     * is not an exotic state, it is what the table looks like for as long as a
     * school is part-way through moving over — and treating "has any structure
     * row" as "is fully migrated" silently stopped paying every head that had not
     * been moved yet.
     *
     * ── WHAT COUNTS AS REPLACED, AND WHY IT IS NOT THE RESOLVED LINES ────────
     * Coverage is decided against the employee's STRUCTURE ROWS, not against the
     * lines resolved for this period, and it matches in three ways, all within
     * the same TYPE:
     *
     *   1. `legacy_name` — the head name the row was migrated from, recorded on
     *      the row itself. This is the durable one.
     *   2. the code the entry's name normalises to ("H.R.A." → HRA), and
     *   3. an exact name match,
     *
     * both of which read the component AS IT IS TODAY and so only hold until
     * somebody renames the head. Renaming "House Rent Allowance" to "HRA" used to
     * make the JSON entry look unmigrated and paid it a SECOND time on top of the
     * structure row — gross ₹52,800 → ₹65,600, two HRA lines, no warning. (1) is
     * why that can no longer happen; (2) and (3) stay for rows written before the
     * column existed and for heads migrated by hand since.
     *
     * TYPE IS PART OF THE MATCH. It was not, and a legacy DEDUCTION whose name
     * happened to match an ALLOWANCE head was treated as already migrated and
     * silently dropped — an amount that should have come out of somebody's pay
     * and did not, with nothing on the payslip to show it.
     *
     * A row counts once it has STARTED (`effective_from` on or before the end of
     * the period) and goes on counting after it is CLOSED. Both halves matter:
     *   • a row dated to start next month must not stop the legacy entry being
     *     paid THIS month, and
     *   • closing a row — the way an administrator STOPS paying a head — must not
     *     resurrect the legacy entry it replaced, which would make removing a
     *     head from somebody's salary impossible.
     * Anything else is still owed and is still paid.
     *
     * ── AND THE LIMIT OF THIS ────────────────────────────────────────────────
     * The JSON columns have no history and cannot be given one: there is exactly
     * one blob per employee and editing it rewrites the past. A period priced
     * from a legacy head is therefore a period that CAN move if somebody edits
     * the staff record. Effective dating fixes that for every head that has been
     * migrated; for the rest the protection is the payroll LOCK, which is why
     * issuing a payslip locks it. The note this returns is how an operator sees
     * which employees are still exposed.
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<int, array<string, mixed>>
     */
    protected function uncoveredLegacyLines(Staff $staff, array $resolved, string $periodEnd): array
    {
        if (empty($staff->allowances) && empty($staff->deductions)) {
            return [];
        }

        $covered = $this->coveredLegacyKeys($staff, $resolved, $periodEnd);

        $lines = [];
        $sequence = 100;

        foreach (['allowance' => $staff->allowances, 'deduction' => $staff->deductions] as $type => $raw) {
            foreach ((array) ($raw ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));
                $amount = (float) ($entry['amount'] ?? 0);

                // The same filter payroll has always applied: a blank name or a
                // zero amount was never paid and must not start being paid now.
                if ($name === '' || $amount == 0.0) {
                    continue;
                }

                if (isset($covered[$type.'|'.strtolower($name)])
                    || isset($covered[$type.'#'.SalaryComponent::codeFor($name)])) {
                    continue;
                }

                $lines[] = [
                    'component' => null,
                    'component_id' => null,
                    'code' => null,
                    'name' => $name,
                    'type' => $type,
                    'amount' => $amount,
                    'source' => 'legacy',
                    'is_taxable' => $type === 'allowance',
                    'pf_wage' => false,
                    'esi_wage' => $type === 'allowance',
                    // Legacy heads were paid in full regardless of attendance and
                    // keep doing so, so switching the engine on does not silently
                    // start docking a head nobody agreed to prorate.
                    'prorates_on_lop' => false,
                    'emit_as_line' => true,
                    'sequence' => $sequence += 10,
                    'legacy_name' => $name,
                    'supersedes_statutory_kind' => null,
                ];
            }
        }

        return $lines;
    }

    /**
     * Every key under which a legacy JSON entry counts as already migrated.
     *
     * Keys are `type|lowercased name` and `type#NORMALISED_CODE`, so a lookup is
     * one array test per entry and the TYPE can never be crossed.
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<string, true>
     */
    protected function coveredLegacyKeys(Staff $staff, array $resolved, string $periodEnd): array
    {
        $covered = [];

        $add = static function (?string $type, ?string $name, ?string $code) use (&$covered): void {
            if ($type === null) {
                return;
            }

            $name = trim((string) $name);

            if ($name !== '') {
                $covered[$type.'|'.strtolower($name)] = true;
                $covered[$type.'#'.SalaryComponent::codeFor($name)] = true;
            }

            $code = trim((string) $code);

            if ($code !== '') {
                $covered[$type.'#'.$code] = true;
            }
        };

        // Structure rows that have STARTED, closed or not. See the note above.
        // Soft-deleted rows STILL COVER. Deleting a structure row is one of the
        // two ways an administrator stops paying a head (closing it with an
        // `effective_to` is the other); if a deleted row stopped covering, the
        // legacy JSON entry it replaced would come back to life and the head
        // would go on being paid forever with no row anywhere to point at.
        $rows = StaffSalaryStructure::query()
            ->withoutGlobalScopes()
            ->where('staff_id', $staff->id)
            ->whereDate('effective_from', '<=', $periodEnd)
            ->with(['component' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($rows as $row) {
            $component = $row->component;

            if ($component === null) {
                continue;
            }

            $add($component->type, $row->legacy_name, null);
            $add($component->type, $component->name, $component->code);
        }

        // Lines resolved for this period that came from no structure row at all
        // (nothing does today, but a future head might): they still cover.
        foreach ($resolved as $line) {
            if (($line['source'] ?? null) !== 'structure') {
                continue;
            }

            $add($line['type'] ?? null, $line['name'] ?? null, $line['code'] ?? null);
        }

        return $covered;
    }

    /**
     * One priced line in the shape the payroll engine consumes.
     *
     * @return array<string, mixed>
     */
    protected function line(SalaryComponent $component, float $amount, string $source, ?StaffSalaryStructure $structure = null): array
    {
        return [
            'component' => $component,
            'component_id' => $component->id,
            'code' => $component->code,
            'name' => $component->name,
            'type' => $component->type,
            'amount' => round($amount, 2),
            'source' => $source,
            'is_taxable' => (bool) $component->is_taxable,
            'pf_wage' => (bool) $component->pf_wage,
            'esi_wage' => (bool) $component->esi_wage,
            'prorates_on_lop' => (bool) $component->prorates_on_lop,
            'emit_as_line' => (bool) $component->emit_as_line,
            'sequence' => (int) $component->display_order,
            'structure_id' => $structure?->id,
            'legacy_name' => $structure?->legacy_name,
            'supersedes_statutory_kind' => $component->supersededStatutoryKind(),
        ];
    }

    /** Last calendar day of a payroll period, as Y-m-d. */
    public static function periodEnd(int $month, int $year): string
    {
        return date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
    }

    /** First calendar day of a payroll period, as Y-m-d. */
    public static function periodStart(int $month, int $year): string
    {
        return sprintf('%04d-%02d-01', $year, $month);
    }
}
