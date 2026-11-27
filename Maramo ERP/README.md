# Classivo ERP – A Complete School Management Kit

A complete school management kit built with Laravel and Filament, as MIT-licensed Composer packages. See [docs/index.md](docs/index.md).

## Demo, docs and services

- Live demo: https://demo.classivoerp.com (read-only sign-in: `viewer@example.com` / `password`)
- Full documentation of all features: https://docs.classivoerp.com
- Website: https://classivoerp.com
- We offer free customisation and custom development services. Email us at info@classivoerp.com or contact us through our website: https://classivoerp.com/contact/
- Ready-made mobile apps are available as a paid add-on: Admin app, Teacher app and Student app. (They are not part of this repository.)

## What is in this repository

| Path | Composer name | What it does |
|---|---|---|
| `packages/core` | `classivo/core` | Classivo ERP core: school, branch, academic session, classes, sections and subjects with a pluggable tenancy contract. |
| `packages/students` | `classivo/students` | Classivo ERP students module: admission, guardians, categories, documents, promotion and daily/subject attendance, with a Filament plugin. |
| `packages/exams` | `classivo/exams` | Classivo ERP exams: exam groups, schedules, marks entry, grading scale, divisions, result generation, ranking and publishing. |
| `packages/report-cards` | `classivo/report-cards` | Report card, marksheet, certificate, ID card and transfer certificate PDF engine for Laravel (dompdf). Works standalone from plain data; optional Classivo adapter. |
| `packages/grading` | `classivo/grading` | Pure-PHP school grading engine: pluggable schemes (CBSE, ICSE/ISC, configurable state boards), term weightage, best-of-N, rounding, pass/fail with grace marks, co-scholastic grades. Optional Laravel integration. |
| `packages/timetable` | `classivo/timetable` | Classivo ERP timetable: class routines with clash detection, plus a framework-agnostic clash-free timetable generator. |
| `packages/pdf` | `classivo/pdf` | Small HTML-to-PDF contract for Laravel with a dompdf driver and an optional Chrome driver (spatie/browsershot) for scripts dompdf cannot shape, such as Devanagari. |
| `packages/demo-guard` | `classivo/demo-guard` | Safeguards for a public Classivo ERP demo: blocks destructive edits, caps uploads and shows a nightly-reset banner. Active only when APP_ENV=demo. |
| `packages/hr` | `classivo/hr` | Classivo ERP HR & payroll: staff, departments, attendance, leave, salary structures, payroll engine and an India statutory preset (PF/ESI/PT/TDS) over a country-neutral core. |
| `packages/fees` | `classivo/fees` | Classivo ERP fees: fee heads, groups and masters, allocation, concessions, late fines, manual receipts (cash/cheque/bank transfer), refunds with credit notes, carry-forward, session rollover, defaulters and e-mail reminders. No payment gateway and no accounting: money movements are announced as events. |
| `app` | `classivo/demo` | A Laravel 12 + Filament 4 demo app that uses the packages through Composer path repositories, with a demo seeder and English + Hindi UI. |

### Coming soon

More modules are released in stages over the coming weeks: library, transport, front-office, hostel, online-exams, communication, payments, finance, student-life, lms, skill-programs, academics-extras, inventory, cms, udise, platform, security, documents, reports.

## Quick start with Docker

```bash
git clone https://github.com/classivoschoolerp/classivo-erp.git classivo-erp
cd classivo-erp
docker compose up
```

The first start installs dependencies, migrates and seeds a fictional demo school. Open http://localhost:8000/admin and sign in with `admin@example.com` / `password`.

## Quick start without Docker

Requirements: PHP 8.2+ with `intl`, `pdo_sqlite` (or `pdo_mysql`), Composer 2.

```bash
cd app
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Switch the interface language with `?lang=hi` / `?lang=en` (or the links in the top bar).

## Using the packages in your own Laravel app

```php
// app/Providers/Filament/AdminPanelProvider.php
->plugins([
    \Classivo\Core\CorePlugin::make(),
    \Classivo\Students\StudentsPlugin::make(),
])
->middleware([
    // ... Laravel / Filament defaults ...
    \Classivo\Core\Http\Middleware\ResolveTenantContext::class,
], isPersistent: true)
```

Single school by default. To serve many schools from one install, bind your own resolver:

```php
$this->app->bind(\Classivo\Core\Tenancy\TenantResolver::class, MySchoolResolver::class);
```

Modules talk to each other through Laravel events (for example `Classivo\Students\Events\StudentAdmitted`, `StudentsMarkedAbsent`, `StudentPromoted`), so you can add notifications, logins or fees without changing the packages.

## Tests

```bash
cd packages/core && composer install && vendor/bin/phpunit
cd packages/students && composer install && vendor/bin/phpunit
cd app && composer install && php artisan test
```

All suites run on SQLite in memory.

## Contributing

Contributions are welcome. Every commit must carry a Developer Certificate of Origin sign-off (`git commit -s`). See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. Copyright (c) 2026 PRS CloudTech Private Limited. See [LICENSE](LICENSE).
