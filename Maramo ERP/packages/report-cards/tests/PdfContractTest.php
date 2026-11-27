<?php

namespace Classivo\ReportCards\Tests;

use Classivo\Pdf\PdfRenderer as Contract;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Classivo\ReportCards\Samples\SampleData;

final class SpyPdf implements Contract
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
    public function test_hindi_marksheet_goes_through_the_pdf_manager(): void
    {
        $spy = new SpyPdf;
        $this->app->instance(Contract::class, $spy);
        app()->setLocale('hi');

        $out = app(PdfRenderer::class)->marksheet(SampleData::marksheet(), 'generic');

        $this->assertSame('%PDF-spy', $out);
        $this->assertCount(1, $spy->calls);
        $this->assertSame('hi', $spy->calls[0][2]);
        $this->assertSame('a4', $spy->calls[0][1]['paper']);
    }
}
