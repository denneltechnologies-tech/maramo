<?php

namespace Classivo\Hr\Services\Payroll;

use Classivo\Core\Support\Preset;
use Classivo\Hr\Models\SalaryComponent;
use Illuminate\Support\Facades\DB;

/**
 * SalaryComponentProvisioner — gives a branch the salary heads the payroll
 * engine looks up by code.
 *
 * ── WHY THIS IS A SERVICE AND NOT JUST A MIGRATION ───────────────────────────
 * The migration that creates `salary_components` provisions every branch that
 * existed when it ran. That covers today's schools and no future one: a branch
 * created next month by the Branches screen, or a whole new tenant created by
 * the SaaS provisioner, would have an empty head master and payroll would be
 * computing statutory lines with no component behind them.
 *
 * So the definition lives here, the migration calls this, and
 * {@see SalaryStructureResolver} calls it lazily the first time it resolves a
 * branch that has no system heads. A tenant therefore cannot end up without
 * them no matter which route created it — including the route where somebody
 * forgets to wire a new one up.
 *
 * ── IDEMPOTENT, ALWAYS ───────────────────────────────────────────────────────
 * Every write is an insertOrIgnore against the unique (branch_id, code) index,
 * so running this twice is a no-op and running it on a branch whose heads an
 * admin has since renamed leaves those names alone. It ADDS what is missing and
 * never overwrites what is there.
 */
class SalaryComponentProvisioner
{
    /**
     * The heads for the ACTIVE preset: the jurisdiction-free core heads (basic,
     * loss of pay, advance) plus the preset's statutory heads (config hr.php).
     *
     * ADVANCE is named 'Advance salary' to the letter because that is the exact
     * string PayrollService writes into `payroll_details.name` for an approved
     * advance. BASIC and TDS-style heads emit no payslip line when the figure is
     * already carried by its own payrolls column.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function components(): array
    {
        return array_merge(
            (array) config('hr.core_components', []),
            (array) config('hr.presets.'.Preset::name().'.statutory_components', []),
        );
    }

    /**
     * Core heads plus the statutory heads of EVERY configured preset, so the
     * last-resort lookups below still work for a head created under another preset.
     *
     * @return array<int, array<int, mixed>>
     */
    protected static function allComponents(): array
    {
        $all = (array) config('hr.core_components', []);

        foreach ((array) config('hr.presets', []) as $preset) {
            $all = array_merge($all, (array) ($preset['statutory_components'] ?? []));
        }

        return $all;
    }

    /**
     * Branches already provisioned in this process.
     *
     * Payroll generation resolves a structure per employee, so without this the
     * "are the heads there?" check would run once per staff member per run. The
     * memo is per-process and per-branch, which is exactly the lifetime of a
     * generation run.
     *
     * @var array<int, true>
     */
    private static array $checked = [];

    /**
     * Ensure one branch has the system heads. Cheap to call repeatedly.
     */
    public function ensureFor(int $schoolId, int $branchId): void
    {
        if (isset(self::$checked[$branchId])) {
            return;
        }

        self::$checked[$branchId] = true;

        $present = DB::table('salary_components')
            ->where('branch_id', $branchId)
            ->where('is_system', true)
            ->whereNull('deleted_at')
            ->count();

        if ($present >= count(self::components())) {
            return;
        }

        DB::table('salary_components')->insertOrIgnore($this->rowsFor($schoolId, $branchId));
    }

    /**
     * Ensure EVERY branch has them — what the creating migration runs, and a
     * safe repair for any install that has drifted.
     */
    public function ensureAllBranches(): void
    {
        DB::table('branches')->orderBy('id')->select('id', 'school_id')->chunk(200, function ($branches): void {
            $rows = [];

            foreach ($branches as $branch) {
                $rows = array_merge($rows, $this->rowsFor((int) $branch->school_id, (int) $branch->id));
            }

            if ($rows !== []) {
                DB::table('salary_components')->insertOrIgnore($rows);
            }
        });
    }

    /**
     * Rows for one branch.
     *
     * Built with the DB facade rather than the Eloquent model on purpose:
     * {@see SalaryComponent} carries tenant and branch global scopes, and this
     * runs from a migration and from console context where those scopes are not
     * resolved to the branch being provisioned.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(int $schoolId, int $branchId): array
    {
        $now = now();
        $rows = [];

        foreach (self::components() as [$code, $name, $type, $calculation, $kind, $taxable, $prorates, $emit, $order, $pfWage, $esiWage]) {
            $rows[] = [
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'calculation' => $calculation,
                'percentage' => null,
                'base_codes' => null,
                'statutory_kind' => $kind,
                'is_taxable' => $taxable,
                'pf_wage' => $pfWage,
                'esi_wage' => $esiWage,
                'prorates_on_lop' => $prorates,
                'emit_as_line' => $emit,
                'display_order' => $order,
                'is_system' => true,
                'status' => true,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * The type a system head has, by code.
     *
     * The engine's LAST-RESORT answer when a head has somehow been deleted:
     * without it, a missing PF_EMPLOYER component would fall back to
     * "deduction" and the SCHOOL'S OWN contribution would come out of the
     * EMPLOYEE'S pay. That is the single worst thing this module could do
     * silently, so the mapping does not depend on a row existing.
     */
    public static function typeForCode(string $code): string
    {
        foreach (self::allComponents() as [$c, , $type]) {
            if ($c === $code) {
                return $type;
            }
        }

        return SalaryComponent::TYPE_DEDUCTION;
    }

    /**
     * The statutory calculation a system head is bound to, by code.
     *
     * The same last-resort mapping as {@see typeForCode()}, for the same reason:
     * the engine must be able to say WHICH obligation a computed line represents
     * even when the head master row behind it has been deleted — otherwise the
     * hand-entered head it supersedes cannot be recognised and the employee is
     * deducted twice.
     */
    public static function statutoryKindForCode(string $code): ?string
    {
        foreach (self::allComponents() as [$c, , , , $kind]) {
            if ($c === $code) {
                return $kind;
            }
        }

        return null;
    }

    /** Forget the memo — tests that create branches between generation runs. */
    public static function flush(): void
    {
        self::$checked = [];
    }
}
