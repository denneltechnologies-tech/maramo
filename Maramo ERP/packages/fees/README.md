# classivo/fees

Classivo ERP fees: fee heads, groups and masters, allocation, concessions, late fines, manual receipts (cash/cheque/bank transfer), refunds with credit notes, carry-forward, session rollover, defaulters and e-mail reminders. No payment gateway and no accounting: money movements are announced as events.

## Installation and setup

## 1. Composer (app/composer.json)

```json
"repositories": [
    { "type": "path", "url": "../packages/fees", "options": { "symlink": true } }
],
"require": {
    "classivo/fees": "*@dev"
}
```

Requires `classivo/core` and `classivo/students` (Student, Guardian, StudentCategory,
StudentSessionRecord). Optional: `spatie/laravel-permission` for role-based grants.

The service provider is auto-discovered (`Classivo\Fees\FeesServiceProvider`).
dompdf (`barryvdh/laravel-dompdf`) is pulled in for receipts and credit notes.

## 2. Filament panel (app/Providers/Filament/AdminPanelProvider.php)

```php
->plugin(\Classivo\Fees\FeesPlugin::make())                  // admin panel
->plugin(\Classivo\Fees\FeesPortalPlugin::student())        // student panel: My Fees
->plugin(\Classivo\Fees\FeesPortalPlugin::guardian())       // guardian panel: Children's Fees
```

Admin screens: Fee Types, Fee Groups, Fee Masters, Concession catalogue and register,
Receipts, Refunds, Offline Payments, Collect Fees (counter), Assign Fees, Search Due
Fees, Fee Reminders, Carry Forward, Session Rollover, Fee Reports.

The panel must already carry the core `classivo.tenant` middleware.

## 3. Migrations

`php artisan migrate` — 18 tables (`fee_*`, `offline_payments`). Run after the core
and students migrations. Student/user/category ids are plain indexed columns (no
cross-package foreign keys).

## 4. Scheduler (routes/console.php)

```php
Schedule::command('fees:accrue-fines')->dailyAt('01:00');
Schedule::command('fees:send-reminders')->dailyAt('04:00');
```

Mail must be configured (reminders use the `mail` notification channel only).

## 5. Permissions (deny by default)

Names are in `Classivo\Fees\Support\FeesPermissions`: `fees.setup`, `fees.assign`,
`fees.view`, `fees.collect`, `fees.revert`, `fees.discount`, `fees.refund`,
`fees.refund.approve`, `fees.refund.issue`, `fees.dues.view`, `fees.reminders.send`,
`fees.carry_forward`, `fees.rollover`, `fees.reports.view`.

With spatie/laravel-permission, add to `database/seeders/DatabaseSeeder.php`:

```php
$this->call(\Classivo\Fees\Database\Seeders\FeesPermissionSeeder::class);
```

and list the roles to grant in `config/fees.php` → `grant_to_roles` (e.g. `['Admin',
'Accountant']`). Without spatie, each ability falls back to a truthy `is_admin` on the
user (`fees.define_gates`). Receipt PDFs use the `view-fee-receipt` gate: staff with
`fees.collect` or `fees.view`, the student, or a linked guardian (matched on `user_id`).

## 6. Extension points (bind in AppServiceProvider)

```php
$this->app->bind(\Classivo\Fees\Contracts\CollectionGuard::class, MyClosedPeriodGuard::class);   // refuse closed periods
$this->app->bind(\Classivo\Fees\Contracts\HolidayCalendar::class, MyCalendarHolidays::class);    // skip closures in daily fines
$this->app->bind(\Classivo\Fees\Contracts\ReminderRecipients::class, MyRecipients::class);       // who gets reminder e-mails
```

## 7. Events for the closed accounting module (or anything else)

```php
Event::listen(\Classivo\Fees\Events\FeePaymentRecorded::class, ...); // receipt committed
Event::listen(\Classivo\Fees\Events\FeePaymentReverted::class, ...); // whole receipt reverted
Event::listen(\Classivo\Fees\Events\FeeRefunded::class, ...);        // refund issued (credit note)
Event::listen(\Classivo\Fees\Events\FeeAccrued::class, ...);         // late fine accrued (delta)
```

All carry plain scalars (ids, amounts, dates, school/branch/session ids).

## 8. Config

`php artisan vendor:publish --tag=classivo-fees-config` → `config/fees.php`
(receipt prefix, refund thresholds, concession approval, clearance, reminders,
route prefix, permissions). Currency comes from the branch, else `fees.currency`, else the core
preset (India preset = INR / ₹).

## 9. Translations

UI strings go through `__()`; Hindi is in `lang/hi.json` (loaded by the provider), plus
`lang/{en,hi}/fees.php` for the navigation group. Set the app locale to `hi` to use it.

MIT licensed.
