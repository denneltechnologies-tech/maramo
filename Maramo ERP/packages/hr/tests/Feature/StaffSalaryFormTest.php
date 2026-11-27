<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Hr\Filament\Resources\Staff\Pages\CreateStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\EditStaff;
use Classivo\Hr\Filament\Resources\Staff\Schemas\StaffForm;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffSalaryStructure;
use Classivo\Hr\Services\Payroll\SalaryComponentProvisioner;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * The Staff form's salary tab — the screen where the reported failure actually
 * happened.
 *
 * An administrator raised House Rent Allowance from ₹12,800 to ₹15,000 on this
 * form, saved it, saw the new figure read back, and payroll went on paying
 * ₹12,800. Nothing was broken in the engine: they had edited the LEGACY
 * free-text repeater, whose value the resolver ignores head by head wherever a
 * salary-structure row exists. The form's own helper text said payroll read
 * those lists "only when a staff member has no structure rows", which is not
 * what the engine does and is what made the mistake inevitable.
 *
 * These tests pin down the three things that fix it: the structure repeater is
 * the editor and reaches the engine, the legacy repeaters cannot be edited by
 * anyone the engine ignores, and there is a deliberate way to empty them.
 */
class StaffSalaryFormTest extends TestCase
{
    private School $school;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        SalaryComponentProvisioner::flush();
        $this->withoutVite();

        [$this->school, $this->branch] = $this->foundation();

        $this->actingAs($this->adminUser($this->school));
    }

    public function test_the_staff_form_renders_for_a_new_and_an_existing_record(): void
    {
        Livewire::test(CreateStaff::class)->assertOk();
        Livewire::test(EditStaff::class, ['record' => $this->staff()->getKey()])->assertOk();
    }

    public function test_the_form_renders_for_a_migrated_record_with_read_only_legacy_lists(): void
    {
        $staff = $this->migratedStaff();

        Livewire::test(EditStaff::class, ['record' => $staff->getKey()])->assertOk();

        $this->assertTrue(StaffForm::isMigrated($staff));
    }

    public function test_the_legacy_lists_stay_editable_for_a_staff_member_with_no_structure_rows(): void
    {
        $staff = $this->staff();

        $this->assertFalse(StaffForm::isMigrated($staff));
        $this->assertStringContainsString('LIVE', StaffForm::legacyHelperText($staff, 'allowance'));
        $this->assertStringContainsString('NO salary structure rows', StaffForm::legacyStatusLine($staff));
    }

    public function test_a_migrated_record_is_told_which_free_text_entries_are_inert(): void
    {
        $staff = $this->migratedStaff();

        $this->assertStringContainsString('READ-ONLY', StaffForm::legacyHelperText($staff, 'allowance'));
        $this->assertSame(['House Rent Allowance'], StaffForm::shadowedNames($staff));
        $this->assertSame(['Conveyance Allowance'], StaffForm::liveLegacyNames($staff));
        $this->assertStringContainsString('NOT paid: House Rent Allowance', StaffForm::legacyStatusLine($staff));
        $this->assertStringContainsString('still PAID', StaffForm::legacyStatusLine($staff));
    }

    public function test_editing_the_structure_row_is_what_changes_the_pay(): void
    {
        $staff = $this->migratedStaff();
        $payroll = app(PayrollService::class);

        // 30000 basic + 12000 HRA from the structure + 1500 Conveyance still
        // owed from the free text, which is what a part-migrated record pays.
        $this->assertSame(43500.0, $payroll->compute($staff, 9, 2026)['gross']);

        StaffSalaryStructure::where('staff_id', $staff->id)
            ->whereHas('component', fn ($q) => $q->where('code', 'HOUSE_RENT_ALLOWANCE'))
            ->update(['amount' => 15000]);

        $this->assertSame(46500.0, $payroll->compute($staff->fresh(), 9, 2026)['gross']);
    }

    public function test_retiring_the_free_text_salary_is_refused_while_something_is_still_paid(): void
    {
        $staff = $this->migratedStaff();

        Livewire::test(EditStaff::class, ['record' => $staff->getKey()])
            ->callAction(TestAction::make('retireLegacySalary'));

        $this->assertNotEmpty($staff->fresh()->allowances, 'Conveyance Allowance is still being paid from here');
    }

    public function test_retiring_the_free_text_salary_clears_it_once_everything_is_replaced(): void
    {
        $staff = $this->migratedStaff();
        $staff->update(['allowances' => [['name' => 'House Rent Allowance', 'amount' => 12000]]]);

        $before = app(PayrollService::class)->compute($staff->fresh(), 9, 2026)['gross'];

        Livewire::test(EditStaff::class, ['record' => $staff->getKey()])
            ->callAction(TestAction::make('retireLegacySalary'));

        $this->assertSame([], $staff->fresh()->allowances);
        $this->assertSame(
            $before,
            app(PayrollService::class)->compute($staff->fresh(), 9, 2026)['gross'],
            'retiring inert entries must not move anybody’s pay',
        );
    }

    // ---------------------------------------------------------------------

    private function staff(): Staff
    {
        return Staff::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'first_name' => 'Form', 'last_name' => 'Subject',
            'status' => 'active',
            'joining_date' => '2020-01-01',
            'basic_salary' => 30000,
            'allowances' => [
                ['name' => 'House Rent Allowance', 'amount' => 12000],
                ['name' => 'Conveyance Allowance', 'amount' => 1500],
            ],
            'deductions' => [],
        ]);
    }

    /** A staff member in the state the backfill leaves everyone in, but only part way. */
    private function migratedStaff(): Staff
    {
        $staff = $this->staff();

        // Provision the heads the way the resolver does.
        app(PayrollService::class)->compute($staff, 9, 2026);

        $hra = SalaryComponent::withoutGlobalScopes()->firstOrCreate(
            ['branch_id' => $this->branch->id, 'code' => 'HOUSE_RENT_ALLOWANCE'],
            [
                'school_id' => $this->school->id,
                'name' => 'House Rent Allowance',
                'type' => SalaryComponent::TYPE_ALLOWANCE,
                'calculation' => 'fixed',
                'is_taxable' => true, 'esi_wage' => true, 'emit_as_line' => true,
                'display_order' => 180, 'status' => true,
            ],
        );

        StaffSalaryStructure::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'staff_id' => $staff->id,
            'salary_component_id' => $hra->id,
            'legacy_name' => 'House Rent Allowance',
            'amount' => 12000,
            'effective_from' => '2020-01-01',
        ]);

        return $staff->fresh();
    }
}
