# classivo/pdf

Optional HTML-to-PDF contract for Laravel (MIT). dompdf cannot shape Devanagari, so Hindi documents show misplaced vowel signs. This package lets any module render through one contract and switch to headless Chrome for the locales that need it.

## Install

    composer require classivo/pdf
    composer require spatie/browsershot   # optional, Chrome driver only

The Chrome driver needs Chrome or Chromium (and Node with puppeteer, per the Browsershot docs) installed on the server. Set `CLASSIVO_PDF_CHROME_BINARY` if it is not auto-detected, and `CLASSIVO_PDF_NO_SANDBOX=true` when running as root in a container. Install a Devanagari font (for example Noto Sans Devanagari) on the server.

## Config (`classivo-pdf`)

- `driver`: `dompdf` (default) or `chrome`.
- `chrome_for_locales`: locales that use Chrome automatically, e.g. `['hi']` (env `CLASSIVO_PDF_CHROME_LOCALES=hi`).

If Chrome is selected but Browsershot is missing, or rendering throws, the document is rendered with dompdf and a warning is logged.

## Usage

    use Classivo\Pdf\PdfRenderer;

    $bytes = app(PdfRenderer::class)->render($html, ['paper' => 'a4', 'orientation' => 'portrait', 'locale' => 'hi']);

`locale` defaults to `app()->getLocale()`.

## Migration notes for other packages

Each package renders HTML first (`view(...)->render()`), then hands it to the contract. Make `classivo/pdf` a `suggest`, and fall back to the current call when it is not installed.

- report-cards, `src/Renderer/PdfRenderer.php`: replace `Pdf::loadHTML($html)->setPaper($paper, $orientation)->output()` with `app(\Classivo\Pdf\PdfRenderer::class)->render($html, ['paper' => $paper, 'orientation' => $orientation])`.
- hr, `PayrollPdfController`: replace `Pdf::loadView('hr::pdf.payslip', $data)` with `app(\Classivo\Pdf\PdfRenderer::class)->render(view('hr::pdf.payslip', $data)->render())`; respond with the bytes and a `Content-Type: application/pdf` header.
- fees, `FeeReceiptController`, `FeeRefundService` (credit note), `FeeStatementService` (app receipt): same pattern with the `classivo-fees::pdf.*` views.
- online-exams, `OnlineExamPaperController`: same pattern with `classivo-online-exams::pdf.question-paper`.
- front-office, `PrintController`: same pattern with `$view, $data`.

Pass `paper` and `orientation` options where the old code called `setPaper()`. Views should set `font-family` to a Devanagari-capable font for Chrome output.

## Tests

    ../../heavy.sh composer install && ../../heavy.sh vendor/bin/phpunit

The tests mock Browsershot and never start a browser.
