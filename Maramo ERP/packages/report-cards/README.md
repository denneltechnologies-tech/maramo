# classivo/report-cards

Marksheet, report card, certificate, ID card and transfer certificate PDFs for Laravel, built on `barryvdh/laravel-dompdf`.
Works **standalone from plain data**. Nothing from the rest of Classivo is required to render.

```php
use Classivo\ReportCards\Renderer\PdfRenderer;
use Classivo\ReportCards\DTO\{Institution, StudentInfo, SubjectResult, MarksheetData};

$data = new MarksheetData(
    new Institution('Demo School', 'Main Campus'),
    new StudentInfo('Test Student', 'ADM-1', 'X - A'),
    'Annual Exam',
    [new SubjectResult('Maths', 100, 33, 82, grade: 'A2')],
);
file_put_contents('marksheet.pdf', app(PdfRenderer::class)->marksheet($data, 'cbse')); // or 'generic'
```

- Marksheet layouts: `generic`, `cbse` (bundled), or any Blade view name (publish with tag `report-cards-views`).
- Certificates: HTML with `{{tags}}` (`CertificateRenderer::availableVariables()`); values are HTML-escaped.
- `transferCertificate()`, `idCards()` take the DTOs in `src/DTO`.
- Hindi + English labels (`app()->setLocale('hi')`).
- `Classivo\ReportCards\Samples\SampleData` has fake data and two certificate templates.

## Optional layers
- `Adapters\ClassivoAdapter` maps classivo/students + classivo/exams records to the DTOs (duck-typed, arrays work too).
- Persistence (templates, issued certificate log, TC series; needs `classivo/core`): models in `src/Models`, migrations
  loaded when `report-cards.persistence` is true; `Services\CertificateIssuer` mints numbers and stores PDFs.

Images (logo/photo) must be `data:` URIs or paths inside dompdf's allowed chroot. Remote URLs are disabled by default.

License: MIT.
