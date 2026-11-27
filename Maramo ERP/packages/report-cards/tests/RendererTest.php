<?php

namespace Classivo\ReportCards\Tests;

use Classivo\ReportCards\DTO\IdCardSheet;
use Classivo\ReportCards\DTO\Institution;
use Classivo\ReportCards\DTO\MarksheetData;
use Classivo\ReportCards\DTO\StudentInfo;
use Classivo\ReportCards\DTO\SubjectResult;
use Classivo\ReportCards\Renderer\CertificateRenderer;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Classivo\ReportCards\Samples\SampleData;

class RendererTest extends TestCase
{
    public function test_generic_marksheet_pdf(): void
    {
        $this->assertPdf(app(PdfRenderer::class)->marksheet(SampleData::marksheet(), 'generic'));
    }

    public function test_cbse_marksheet_pdf_and_html(): void
    {
        $r = app(PdfRenderer::class);
        $this->assertPdf($r->marksheet(SampleData::marksheet(), 'cbse'));
        $html = $r->marksheetHtml(SampleData::marksheet(), 'cbse');
        $this->assertStringContainsString('Asha Verma', $html);
        $this->assertStringContainsString('Co-Scholastic', $html);
        $this->assertStringContainsString('Mathematics', $html);
    }

    public function test_totals_and_result_are_computed(): void
    {
        $d = (new MarksheetData(SampleData::institution(), SampleData::student(), 'Unit Test', [
            new SubjectResult('Maths', 50, 17, 40),
            new SubjectResult('Science', 50, 17, 10),
        ]))->withComputedTotals();
        $this->assertSame(50.0, $d->total);
        $this->assertSame(100.0, $d->totalFull);
        $this->assertSame(50.0, $d->percentage);
        $this->assertSame('fail', $d->result);
    }

    public function test_absent_subject_renders_ab(): void
    {
        $d = new MarksheetData(SampleData::institution(), SampleData::student(), 'X', [new SubjectResult('Art', 50, 17, null, true)]);
        $html = app(PdfRenderer::class)->marksheetHtml($d);
        $this->assertStringContainsString('>AB<', $html);
    }

    public function test_unknown_template_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PdfRenderer::class)->marksheetHtml(SampleData::marksheet(), 'nope');
    }

    public function test_hindi_labels(): void
    {
        app()->setLocale('hi');
        $html = app(PdfRenderer::class)->marksheetHtml(SampleData::marksheet(), 'generic');
        $this->assertStringContainsString('अंकपत्र', $html);
    }

    public function test_certificate_samples_pdf(): void
    {
        $r = app(PdfRenderer::class);
        foreach (SampleData::certificateTemplates() as $content) {
            $this->assertPdf($r->certificate($content, SampleData::certificateVariables(), SampleData::institution()));
        }
    }

    public function test_substitution_escapes_values_and_keeps_unknown_tags(): void
    {
        $out = (new CertificateRenderer)->substitute('Hi {{name}} {unknown} {{qr_code}}', ['name' => '<script>x</script>', 'qr_code' => '<img>']);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('{unknown}', $out);
        $this->assertStringContainsString('<img>', $out);
    }

    public function test_transfer_certificate_pdf(): void
    {
        $r = app(PdfRenderer::class);
        $this->assertPdf($r->transferCertificate(SampleData::transferCertificate()));
        $this->assertStringContainsString('TC0001', $r->transferCertificateHtml(SampleData::transferCertificate()));
    }

    public function test_id_cards_pdf_with_qr(): void
    {
        $r = app(PdfRenderer::class);
        $this->assertPdf($r->idCards(SampleData::idCards()));
        $this->assertStringContainsString('data:image/svg+xml', $r->idCardsHtml(SampleData::idCards()));
    }

    public function test_id_card_colour_cannot_inject_css(): void
    {
        $sheet = new IdCardSheet(SampleData::institution(), [], 'red;} body{display:none', '#fff');
        $html = app(PdfRenderer::class)->idCardsHtml($sheet);
        $this->assertStringNotContainsString('display:none', $html);
    }

    public function test_array_constructors(): void
    {
        $i = Institution::fromArray(['name' => 'Demo School']);
        $s = StudentInfo::fromArray(['name' => 'Test Student', 'admission_no' => 'A1']);
        $d = new MarksheetData($i, $s, 'Mid Term', [new SubjectResult('Maths', 100, 33, 70)]);
        $this->assertPdf(app(PdfRenderer::class)->marksheet($d, 'cbse'));
    }
}
