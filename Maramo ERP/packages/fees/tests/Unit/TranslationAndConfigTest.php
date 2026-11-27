<?php

namespace Classivo\Fees\Tests\Unit;

use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Tests\TestCase;

class TranslationAndConfigTest extends TestCase
{
    public function test_english_and_hindi_strings_exist(): void
    {
        $this->assertSame('Fees Collection', __('classivo-fees::fees.navigation_group'));

        app()->setLocale('hi');
        $this->assertSame('शुल्क संग्रह', __('classivo-fees::fees.navigation_group'));
    }

    public function test_receipt_modes_manual_ones_exclude_online(): void
    {
        $this->assertSame(['cash', 'cheque', 'dd', 'bank_transfer', 'upi', 'card', 'online'], array_keys(FeePayment::MODES));
        $this->assertSame(['cash', 'cheque', 'dd', 'bank_transfer', 'upi', 'card'], array_keys(FeePayment::MANUAL_MODES));
        $this->assertArrayNotHasKey('razorpay', FeePayment::MODES);
    }
}
