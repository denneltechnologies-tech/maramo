<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Filament\Resources\FeeDiscounts\FeeDiscountResource;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Pages\ListFeeDiscounts;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\Pages\ListFeeDiscountStudents;
use Classivo\Fees\Filament\Resources\FeeGroups\FeeGroupResource;
use Classivo\Fees\Filament\Resources\FeeGroups\Pages\CreateFeeGroup;
use Classivo\Fees\Filament\Resources\FeeGroups\Pages\ListFeeGroups;
use Classivo\Fees\Filament\Resources\FeeMasters\FeeMasterResource;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\CreateFeeMaster;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\EditFeeMaster;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\ListFeeMasters;
use Classivo\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use Classivo\Fees\Filament\Resources\FeePayments\Pages\ListFeePayments;
use Classivo\Fees\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Classivo\Fees\Filament\Resources\FeeRefunds\Pages\ListFeeRefunds;
use Classivo\Fees\Filament\Resources\FeeRefunds\Pages\ViewFeeRefund;
use Classivo\Fees\Filament\Resources\FeeTypes\FeeTypeResource;
use Classivo\Fees\Filament\Resources\FeeTypes\Pages\CreateFeeType;
use Classivo\Fees\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
use Classivo\Fees\Filament\Resources\OfflinePayments\OfflinePaymentResource;
use Classivo\Fees\Filament\Resources\OfflinePayments\Pages\ListOfflinePayments;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Tests\UiTestCase;
use Livewire\Livewire;

class FeePanelTest extends UiTestCase
{
    public function test_fee_screens_render(): void
    {
        $user = $this->userWith(FeesPermissions::all());

        $student = $this->student();
        $master = $this->master(1500);
        $allocation = app(FeeAllocationService::class)->allocate($master, $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 1500]]);
        $refund = app(FeeRefundService::class)->request($payment, ['amount' => 100, 'reason_code' => 'excess_payment'], $user);

        Livewire::test(ListFeeTypes::class)->assertOk();
        Livewire::test(CreateFeeType::class)->assertOk();
        Livewire::test(ListFeeGroups::class)->assertOk();
        Livewire::test(CreateFeeGroup::class)->assertOk();
        Livewire::test(ListFeeMasters::class)->assertOk();
        Livewire::test(CreateFeeMaster::class)->assertOk();
        Livewire::test(EditFeeMaster::class, ['record' => $master->getRouteKey()])->assertOk();
        Livewire::test(ListFeeDiscounts::class)->assertOk();
        Livewire::test(ListFeeDiscountStudents::class)->assertOk();
        Livewire::test(ListFeePayments::class)->assertOk()->assertSee($payment->receipt_no);
        Livewire::test(ViewFeePayment::class, ['record' => $payment->getRouteKey()])->assertOk();
        Livewire::test(ListFeeRefunds::class)->assertOk();
        Livewire::test(ViewFeeRefund::class, ['record' => $refund->getRouteKey()])->assertOk();
        Livewire::test(ListOfflinePayments::class)->assertOk();
    }

    public function test_resources_deny_direct_url_access_without_permission(): void
    {
        $master = $this->master(1200);
        $type = FeeType::query()->firstOrFail();

        $this->userWith([]);
        foreach ([FeeTypeResource::class, FeeGroupResource::class, FeeMasterResource::class, FeeDiscountResource::class, FeePaymentResource::class, FeeRefundResource::class, OfflinePaymentResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
        }
        $this->get(FeeTypeResource::getUrl('create'))->assertForbidden();
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $type]))->assertForbidden();
        $this->get(FeeMasterResource::getUrl('edit', ['record' => $master]))->assertForbidden();
        $this->get(OfflinePaymentResource::getUrl('create'))->assertForbidden();

        // Viewing receipts does not unlock fee setup.
        $this->userWith([FeesPermissions::VIEW]);
        $this->get(FeePaymentResource::getUrl('index'))->assertOk();
        $this->get(FeeTypeResource::getUrl('create'))->assertForbidden();
        $this->get(FeeMasterResource::getUrl('edit', ['record' => $master]))->assertForbidden();
    }

    public function test_fee_type_can_be_created_from_the_panel(): void
    {
        $this->userWith([FeesPermissions::SETUP]);

        Livewire::test(CreateFeeType::class)
            ->fillForm(['name' => 'Exam Fee', 'code' => 'EXM'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(FeeType::query()->where('code', 'EXM')->exists());
    }
}
