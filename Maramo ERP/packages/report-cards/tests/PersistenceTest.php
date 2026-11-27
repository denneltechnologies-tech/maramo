<?php

namespace Classivo\ReportCards\Tests;

use Classivo\Core\CoreServiceProvider;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Models\TransferCertificate;
use Classivo\ReportCards\Samples\SampleData;
use Classivo\ReportCards\Services\CertificateIssuer;
use Illuminate\Support\Facades\Storage;

class PersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(CoreServiceProvider::class)) {
            $this->markTestSkipped('classivo/core not installed');
        }
        $this->app['config']->set('report-cards.persistence', true);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate')->run();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [CoreServiceProvider::class]);
    }

    public function test_issue_numbers_and_store_pdf(): void
    {
        Storage::fake('local');
        $tpl = CertificateTemplate::withoutGlobalScopes()->create([
            'school_id' => 1, 'name' => 'Generic', 'content' => SampleData::certificateTemplates()['generic'],
        ]);
        $issuer = app(CertificateIssuer::class);
        $a = $issuer->issue($tpl, SampleData::certificateVariables(), 1);
        $b = $issuer->issue($tpl, SampleData::certificateVariables(), 2);
        $this->assertSame('CERT00001', $a->certificate_number);
        $this->assertSame('CERT00002', $b->certificate_number);
        $this->assertStringContainsString('Asha Verma', $a->renderedHtml());

        $path = $issuer->storePdf($a, SampleData::institution());
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));
    }

    public function test_tc_number_series(): void
    {
        TransferCertificate::withoutGlobalScopes()->create(['school_id' => 1, 'student_id' => 1, 'tc_number' => 'TC0009']);
        $this->assertSame('TC0010', TransferCertificate::nextNumber(1));
    }
}
