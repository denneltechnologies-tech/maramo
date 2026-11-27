<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;

class PayslipFontTest extends TestCase
{
    private function payslip(string $locale): string
    {
        [$school] = $this->foundation();
        $this->adminUser($school);
        $staff = Staff::create(['first_name' => 'अनीता', 'last_name' => 'शर्मा', 'joining_date' => '2028-01-01', 'basic_salary' => 30000, 'status' => 'active']);
        $payroll = app(PayrollService::class)->generate($staff, 8, 2030);

        app()->setLocale($locale);
        $response = $this->get(route('payroll.payslip', ['payroll' => $payroll->id]));
        $response->assertOk();

        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        if (getenv('HR_PAYSLIP_SAMPLE')) {
            file_put_contents(getenv('HR_PAYSLIP_SAMPLE').'-'.$locale.'.pdf', $pdf);
        }

        return $pdf;
    }

    public function test_bundled_font_and_its_licence_ship_with_the_package(): void
    {
        $dir = __DIR__.'/../../resources/fonts';

        $this->assertFileExists($dir.'/NotoSansDevanagari-Regular.ttf');
        $this->assertFileExists($dir.'/NotoSansDevanagari-Bold.ttf');
        $this->assertStringContainsString('SIL Open Font License', (string) file_get_contents($dir.'/OFL.txt'));
    }

    public function test_hindi_payslip_renders_with_the_devanagari_font(): void
    {
        $pdf = $this->payslip('hi');

        $this->assertStringContainsString('NotoSansDevanagari', $pdf, 'the Devanagari font is embedded in the PDF');
    }

    public function test_english_payslip_keeps_the_latin_font(): void
    {
        $pdf = $this->payslip('en');

        $this->assertStringNotContainsString('NotoSansDevanagari', $pdf);
        $this->assertStringContainsString('DejaVu', $pdf);
    }
}
