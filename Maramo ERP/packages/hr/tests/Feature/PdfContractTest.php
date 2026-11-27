<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;
use Classivo\Pdf\PdfRenderer;
use Classivo\Pdf\PdfRenderer as Contract;

final class SpyPdf implements PdfRenderer
{
    public array $calls = [];

    public function render(string $html, array $options = []): string
    {
        $this->calls[] = [$html, $options, app()->getLocale()];

        return '%PDF-spy';
    }
}

class PdfContractTest extends TestCase
{
    public function test_hindi_payslip_goes_through_the_pdf_manager(): void
    {
        [$school] = $this->foundation();
        $this->adminUser($school);
        $staff = Staff::create(['first_name' => 'Anita', 'last_name' => 'Sharma', 'joining_date' => '2028-01-01', 'basic_salary' => 30000, 'status' => 'active']);
        $payroll = app(PayrollService::class)->generate($staff, 8, 2030);

        $spy = new SpyPdf;
        $this->app->instance(Contract::class, $spy);
        app()->setLocale('hi');
        $response = $this->get(route('payroll.payslip', ['payroll' => $payroll->id]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('%PDF-spy', $response->getContent());
        $this->assertCount(1, $spy->calls);
        $this->assertSame('hi', $spy->calls[0][2]);
        $this->assertArrayHasKey('chroot', $spy->calls[0][1]['dompdf']);
    }
}
