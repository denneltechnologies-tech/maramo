<?php

namespace Classivo\ReportCards\Services;

use Classivo\ReportCards\DTO\Institution;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Classivo\ReportCards\Renderer\CertificateRenderer;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Illuminate\Support\Facades\Storage;

/** Persistence helper: mint a number, snapshot the HTML, optionally store the PDF. */
final class CertificateIssuer
{
    public function __construct(private readonly CertificateRenderer $renderer, private readonly PdfRenderer $pdf) {}

    /** @param array<string,string|null> $variables */
    public function issue(CertificateTemplate $template, array $variables, ?int $studentId = null, ?int $staffId = null, ?int $issuedBy = null): GeneratedCertificate
    {
        $number = GeneratedCertificate::nextNumber($template->school_id);
        $variables['certificate_no'] = $number;
        $html = $this->renderer->substitute((string) $template->content, $variables);

        return GeneratedCertificate::create([
            'school_id' => $template->school_id,
            'branch_id' => $template->branch_id,
            'certificate_template_id' => $template->id,
            'student_id' => $studentId,
            'staff_id' => $staffId,
            'certificate_number' => $number,
            'data' => ['variables' => $variables, 'html' => $html],
            'issued_by' => $issuedBy,
            'issued_at' => now(),
        ]);
    }

    /** Render (idempotently) and store the PDF; returns the storage path. */
    public function storePdf(GeneratedCertificate $certificate, Institution $institution): string
    {
        $disk = Storage::disk((string) config('report-cards.disk', 'local'));
        if ($certificate->pdf_path && $disk->exists($certificate->pdf_path)) {
            return $certificate->pdf_path;
        }

        $template = CertificateTemplate::withoutGlobalScopes()->withTrashed()->find($certificate->certificate_template_id);
        $bytes = $this->pdf->certificateBodyPdf(
            $certificate->renderedHtml(), $institution, null,
            $template?->page_size ?? 'a4', $template?->orientation ?? 'portrait',
            'Certificate '.$certificate->certificate_number,
        );
        $path = trim((string) config('report-cards.directory', 'certificates'), '/').'/Certificate-'.$certificate->certificate_number.'.pdf';
        $disk->put($path, $bytes);
        $certificate->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
