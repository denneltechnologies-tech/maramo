<?php

namespace Classivo\ReportCards\Renderer;

use Classivo\ReportCards\DTO\IdCardSheet;
use Classivo\ReportCards\DTO\Institution;
use Classivo\ReportCards\DTO\MarksheetData;
use Classivo\ReportCards\DTO\TransferCertificateData;
use Classivo\ReportCards\Support\QrCode;
use InvalidArgumentException;

/**
 * Standalone PDF engine. Takes plain DTOs / arrays plus a template name and returns
 * PDF bytes (or the HTML). It needs Laravel + barryvdh/laravel-dompdf, nothing from
 * the rest of Classivo.
 *
 * Marksheet templates: "generic", "cbse" (bundled), or any Blade view name that
 * receives the same variables ($data, $institution, $student).
 */
final class PdfRenderer
{
    public const BUNDLED_MARKSHEETS = ['generic', 'cbse'];

    public function __construct(private readonly CertificateRenderer $certificates = new CertificateRenderer) {}

    // ---- marksheet -------------------------------------------------------

    public function marksheetHtml(MarksheetData $data, string $template = 'generic'): string
    {
        $data = $data->withComputedTotals();

        return view($this->marksheetView($template), [
            'data' => $data,
            'institution' => $data->institution,
            'student' => $data->student,
            'generatedOn' => $data->generatedOn ?? now()->format('d M Y H:i'),
        ])->render();
    }

    public function marksheet(MarksheetData $data, string $template = 'generic', string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return $this->pdf($this->marksheetHtml($data, $template), $paper, $orientation);
    }

    // ---- certificates ----------------------------------------------------

    /**
     * @param  string  $content  template HTML with {{tags}} (trusted, admin-authored)
     * @param  array<string,string|null>  $variables
     */
    public function certificateHtml(string $content, array $variables, Institution $institution, ?string $background = null, string $title = 'Certificate'): string
    {
        return view('report-cards::pdf.certificate', [
            'body' => $this->certificates->substitute($content, $variables),
            'institution' => $institution,
            'background' => $background,
            'title' => $title,
        ])->render();
    }

    /** Render the already-substituted HTML snapshot (e.g. a stored issued certificate). */
    public function certificateFromBody(string $bodyHtml, Institution $institution, ?string $background = null, string $title = 'Certificate'): string
    {
        return view('report-cards::pdf.certificate', compact('institution', 'background', 'title') + ['body' => $bodyHtml])->render();
    }

    /** @param array<string,string|null> $variables */
    public function certificate(string $content, array $variables, Institution $institution, ?string $background = null, string $paper = 'a4', string $orientation = 'landscape', string $title = 'Certificate'): string
    {
        return $this->pdf($this->certificateHtml($content, $variables, $institution, $background, $title), $paper, $orientation);
    }

    public function certificateBodyPdf(string $bodyHtml, Institution $institution, ?string $background = null, string $paper = 'a4', string $orientation = 'portrait', string $title = 'Certificate'): string
    {
        return $this->pdf($this->certificateFromBody($bodyHtml, $institution, $background, $title), $paper, $orientation);
    }

    // ---- transfer certificate -------------------------------------------

    public function transferCertificateHtml(TransferCertificateData $tc): string
    {
        return view('report-cards::pdf.transfer-certificate', [
            'tc' => $tc,
            'institution' => $tc->institution,
            'student' => $tc->student,
            'generatedOn' => now()->format('d M Y H:i'),
        ])->render();
    }

    public function transferCertificate(TransferCertificateData $tc): string
    {
        return $this->pdf($this->transferCertificateHtml($tc));
    }

    // ---- id cards --------------------------------------------------------

    public function idCardsHtml(IdCardSheet $sheet): string
    {
        $cards = array_map(fn ($c) => [
            'name' => $c->name,
            'rows' => $c->rows,
            'photo' => $c->photo,
            'qr' => $sheet->showQr && $c->qrPayload !== null ? QrCode::dataUri($c->qrPayload) : null,
        ], $sheet->cards);

        return view('report-cards::pdf.id-card', ['sheet' => $sheet, 'institution' => $sheet->institution, 'cards' => $cards])->render();
    }

    public function idCards(IdCardSheet $sheet, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return $this->pdf($this->idCardsHtml($sheet), $paper, $orientation);
    }

    /**
     * Class tabulation sheet.
     *
     * @param  array{institution:Institution,title:string,examName:string,className:string,subjects:list<string>,rows:list<array<string,mixed>>}  $data
     */
    public function tabulation(array $data): string
    {
        return $this->pdf(view('report-cards::pdf.tabulation', $data)->render(), 'a4', 'landscape');
    }

    // ---- internals -------------------------------------------------------

    public function pdf(string $html, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return app(\Classivo\Pdf\PdfRenderer::class)->render($html, ['paper' => $paper, 'orientation' => $orientation]);
    }

    private function marksheetView(string $template): string
    {
        if (in_array($template, self::BUNDLED_MARKSHEETS, true)) {
            return 'report-cards::marksheet.'.$template;
        }
        if (! view()->exists($template)) {
            throw new InvalidArgumentException("Unknown marksheet template [{$template}]. Use one of ".implode(', ', self::BUNDLED_MARKSHEETS).' or an existing view name.');
        }

        return $template;
    }
}
