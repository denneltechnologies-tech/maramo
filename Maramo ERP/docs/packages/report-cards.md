# classivo/report-cards

Marksheets, report cards, certificates, ID cards and transfer certificates as PDF files.

## What it does

This package makes the printed papers a school hands out.

- Marksheets in two layouts: CBSE style and a generic one.
- Class tabulation sheet (PDF) and a ZIP of all marksheets for a class-section. These show up as links on the Exam Result page when classivo/exams is installed.
- Certificates from templates. You write the text and drop in tags such as the student name, admission number, class, date and certificate number. Tag values are escaped, so they cannot inject HTML.
- Issued certificates get a running number (default prefix `CERT`, 5 digits). Numbers are never reused.
- ID cards. You choose which fields to print, the colours, and whether to show the photo, QR code and logo.
- Transfer certificates with a running number (default prefix `TC`, 4 digits).
- English and Hindi labels.

The PDF engine also works on its own from plain data, without the rest of Classivo. Use `Classivo\ReportCards\Renderer\PdfRenderer` with the data classes in `src/DTO`.

Images (logo, photo) must be `data:` URIs or paths that the PDF library allows. Remote image URLs are turned off.

## Screens

| Screen | What it does | Where in the panel menu |
|---|---|---|
| Report Card Templates | Add and edit marksheet templates: layout, page size, orientation, header and footer HTML, default flag. | Documents |
| Certificate Templates | Write certificate text with tags. The form lists the tags that are available. | Documents |
| ID Card Templates | Choose fields, colours, and photo, QR and logo switches. | Documents |
| Issued Certificates | Issue a certificate to a student and download it as PDF. | Documents |
| Transfer Certificates | Record date of leaving, last class, reason, conduct, working days, days present, promotion status, dues cleared and remarks. Download as PDF. | Documents |

Menu order: Report Card Templates, Certificate Templates, ID Card Templates, Issued Certificates, Transfer Certificates.

## Permissions

All permissions are deny by default. A user needs the permission through their own roles (checked with `$user->can(...)`). The package only reads permissions. It does not create them. Create them in your permission system, for example `spatie/laravel-permission`. `Classivo\ReportCards\Support\Permissions::all()` returns the full list for seeding.

Resource permissions (each of the five resources below gets view, create, update and delete):

| Resource | Permission strings |
|---|---|
| Report Card Templates | `report_card_templates.view`, `.create`, `.update`, `.delete` |
| Certificate Templates | `certificate_templates.view`, `.create`, `.update`, `.delete` |
| ID Card Templates | `id_card_templates.view`, `.create`, `.update`, `.delete` |
| Issued Certificates | `generated_certificates.view`, `.create`, `.update`, `.delete` |
| Transfer Certificates | `transfer_certificates.view`, `.create`, `.update`, `.delete` |

Extra permissions:

| Permission string | Needed for |
|---|---|
| `generated_certificates.issue` | The Issue certificate action. |
| `generated_certificates.download` | Download of an issued certificate. |
| `transfer_certificates.download` | Download of a transfer certificate. |
| `marksheets.download` | Marksheet, marksheets ZIP and tabulation downloads, and the links on the Exam Result page. |

No role gets any of these by default.

## Configuration

Publish with:

```
php artisan vendor:publish --tag=report-cards-config
php artisan vendor:publish --tag=report-cards-views
```

File: `config/report-cards.php`.

| Key | Default | Meaning |
|---|---|---|
| `persistence` | `REPORT_CARDS_PERSISTENCE` env, else true when classivo/core is installed | Loads the templates and issued-certificate tables and migrations. The renderer itself never needs them. |
| `disk` | `local` (env `REPORT_CARDS_DISK`) | Storage disk for issued certificate PDFs. |
| `directory` | `certificates` | Folder on that disk. |
| `certificate_prefix` | `CERT` | Prefix of issued certificate numbers. |
| `transfer_certificate_prefix` | `TC` | Prefix of transfer certificate numbers. |
| `paper` | `a4` | Default paper for the sample templates. |

The `report-cards-views` tag copies the Blade views to `resources/views/vendor/report-cards`, so you can edit layouts. A marksheet layout can also be any Blade view name.

## Events

Emitted: none.

Listened: none. This package does not register listeners. When classivo/exams is installed it binds `Classivo\Exams\Contracts\ResultDocumentLinks` to its own `ResultLinks` class. That adds the tabulation PDF and marksheets ZIP links to the Exam Result page for users with `marksheets.download`.

Routes (added only when classivo/exams is installed, middleware `web` and `auth`):

| Route name | Path |
|---|---|
| `report-cards.marksheet` | `report-cards/exams/{exam}/students/{student}/marksheet` |
| `report-cards.marksheets` | `report-cards/exams/{exam}/sections/{classSection}/marksheets` |
| `report-cards.tabulation` | `report-cards/exams/{exam}/sections/{classSection}/tabulation` |

## Install

```
composer require classivo/report-cards
php artisan migrate
```

The package also pulls in `classivo/pdf`, `barryvdh/laravel-dompdf` and `chillerlan/php-qrcode`. The service provider is discovered automatically and registers dompdf itself.

For the screens, install `filament/filament`, `classivo/core` and `classivo/students`. For marksheet links install `classivo/exams`. Register the plugin:

```php
->plugin(\Classivo\ReportCards\ReportCardsPlugin::make())
```

## Known limitations

- No permission is granted by default. Screens stay hidden until you create and assign the permissions.
- The marksheet routes only exist when classivo/exams is installed.
- The screens need `classivo/core` and `classivo/students` (listed under `suggest` and `require-dev`, not `require`). Without core, the template and issued-certificate tables and models are not loaded.
- Student, exam and guardian data is mapped to marksheet data by `Adapters\ClassivoAdapter`, which reads fields by name. TODO: confirm the exact field mapping for custom student models.
- TODO: confirm the barcode or QR content printed on ID cards beyond "QR image of the identity number".
