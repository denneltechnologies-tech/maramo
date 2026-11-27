# classivo/fees

Fee structure, concessions, late fines, counter collection, receipts, refunds, carry-forward and defaulter reminders for a school.

## What it does

Fees lets the accounts office set up what each student must pay and take the money.

- You create fee types (tuition, bus, exam), group them, and build fee masters that say how much a class pays and when.
- You assign fees to a class. Each student gets their own fee lines.
- You can give concessions (discounts) to students. A school can ask for approval on large ones.
- The counter page collects cash, cheque, demand draft, bank transfer, UPI or card. A numbered receipt (PDF) is made for each payment.
- Late fines are added to overdue lines by a command.
- Staff can raise refunds. Big refunds need one or two approvals. A credit note is made when a refund is issued.
- You can carry balances forward, or roll the fee structure into a new session.
- Defaulters can be found on a search page. Guardians get e-mail reminders at 3, 7, 15 and 30 days overdue.
- Students and guardians can see their fees in read-only portal pages.

There is no payment gateway and no accounting in this package. Money movements are announced as events. Online payment is added by `classivo/payments`.

## Screens

Admin screens sit in the navigation group **Fees Collection**.

| Screen | What it does | Where |
|---|---|---|
| Collect Fees | Counter page: pick a student, choose fee lines, take a payment, print a receipt | Fees Collection |
| Fees Type | Fee heads such as tuition or transport | Fees Collection |
| Fees Group | Groups of fee types | Fees Collection |
| Fees Master | Amount, due dates and schedule per class and fee type | Fees Collection |
| Fees Discount | Concession catalogue | Fees Collection |
| Concession Register | Concessions given to students | Fees Collection |
| Assign Fees | Assign fee masters to a class | Fees Collection |
| Session Rollover | Roll the fee structure into a new session | Fees Collection |
| Carry Forward | Set opening balances by hand | Fees Collection |
| Search Fees Payment | Find and view receipts; revert a receipt | Fees Collection |
| Search Due Fees | Find students with dues | Fees Collection |
| Offline Payments | Review offline payment submissions | Fees Collection |
| Fee Reminders | Reminder board; send reminders | Fees Collection |
| Fee Reports | Fee reports | Fees Collection |
| Fee Refunds | Raise, approve and issue refunds | Fees Collection |
| My Fees | Read-only page for a student (student panel) | Student portal |
| Child Fees | Read-only page for a guardian (guardian panel) | Guardian portal |

Receipt web routes (config `fees.routes`): `GET admin/fees/receipt/{payment}` and `GET admin/fees/receipt/{payment}/pdf`.

## Permissions

All screens are deny by default. No role gets a permission unless you grant it.

| Permission | Allows |
|---|---|
| `fees.setup` | Fee types, groups, masters, discount catalogue |
| `fees.assign` | Assign Fees |
| `fees.view` | See receipts |
| `fees.collect` | Take payments at the counter; Offline Payments |
| `fees.revert` | Revert a whole receipt |
| `fees.discount` | Grant and approve concessions, discount at the counter, override a late fine, Concession Register |
| `fees.refund` | Raise and view refunds |
| `fees.refund.approve` | Approve refunds |
| `fees.refund.issue` | Issue approved refunds |
| `fees.dues.view` | Search Due Fees, Fee Reminders |
| `fees.reminders.send` | Send reminders from the board |
| `fees.carry_forward` | Carry Forward |
| `fees.rollover` | Session Rollover |
| `fees.reports.view` | Fee Reports |

The gate `view-fee-receipt` lets a user open a receipt if they hold `fees.collect` or `fees.view`, or if they are the student or a guardian of that student.

Granting: `FeesPermissionSeeder` (needs `spatie/laravel-permission`) creates the permissions and gives all of them to the roles listed in `fees.grant_to_roles`. The default list is empty. Without spatie, each permission falls back to a truthy `is_admin` attribute on the user (when `fees.define_gates` is true).

## Configuration

Publish: `php artisan vendor:publish --tag=classivo-fees-config` (views: tag `classivo-fees-views`).

| Key | Default | Meaning |
|---|---|---|
| `currency` | `null` (env `FEES_CURRENCY`) | Currency code. Null takes it from the branch, then the core regional preset |
| `currency_symbol` | `null` (env `FEES_CURRENCY_SYMBOL`) | Currency symbol |
| `amount_words` | INR: rupees / paise | Words for "amount in words" on receipts, per currency |
| `receipts.prefix` | `RCPT` | Receipt number prefix (env `FEES_RECEIPT_PREFIX`). A branch `invoice_prefix` wins when present |
| `receipts.start` | `1` | First receipt number (env `FEES_RECEIPT_START`) |
| `due_day` | `10` | Day of month monthly fees fall due (1-28), for "due by" labels |
| `concessions.requires_approval` | `false` | Large concessions wait as pending until approved |
| `concessions.threshold_amount` | `0` | Amount ceiling; 0 turns this test off |
| `concessions.threshold_percent` | `0` | Percent ceiling; 0 turns this test off |
| `refunds.approval_threshold` | `5000` | At or below this a refund is auto-approved |
| `refunds.second_approval_threshold` | `25000` | Above this, two different approvers are needed |
| `refunds.credit_note_prefix` | `CN` | Credit note prefix |
| `refunds.credit_note_start` | `1` | First credit note number |
| `clearance.enabled` | `false` | Withhold documents from defaulters |
| `clearance.min_amount` | `0` | Minimum dues amount that blocks |
| `clearance.min_days` | `30` | Minimum days overdue that blocks |
| `clearance.match` | `any` | `any` or `all` of the two tests above |
| `clearance.services.marksheet` | `true` | Block marksheets |
| `clearance.services.admit_card` | `true` | Block admit cards |
| `clearance.contact` | text | Message shown to a blocked user |
| `reminders.enabled` | `true` | Master switch for e-mail reminders |
| `reminders.cooldown_minutes` | `180` | Minimum gap between reminders to one student |
| `reminders.max_days_overdue` | `120` | Older dues are skipped |
| `reminders.max_per_run` | `500` | Reminder ceiling per run |
| `reminders.escalate.class_teacher_at` | `15` | Days overdue to copy the class teacher |
| `reminders.escalate.back_office_at` | `30` | Days overdue to copy the back office |
| `reminders.escalate.max_back_office` | `3` | Cap on back-office copies |
| `reminders.escalate.office_emails` | empty | Comma list of office addresses (env `FEES_REMINDER_OFFICE_EMAILS`) |
| `define_gates` | `true` | Define `is_admin` fallback gates when spatie is absent |
| `grant_to_roles` | `[]` | Roles the seeder grants every fees permission |
| `student_category_model` | `Classivo\Students\Models\StudentCategory` | Model for per-category pricing |
| `routes.enabled` | `true` | Load the receipt routes |
| `routes.prefix` | `admin/fees` | Receipt route prefix |
| `routes.middleware` | `['web','auth']` | Receipt route middleware |

Commands:

- `php artisan fees:send-reminders` with `--dry-run`, `--school=`, `--stage=` (3, 7, 15 or 30), `--chunk=`, `--limit=`.
- `php artisan fees:accrue-fines` with `--dry-run`, `--school=`. Safe to run again.

The package does not schedule these. Add them to your scheduler (daily is the intent).

Extension points you can bind in the container: `CollectionGuard`, `HolidayCalendar`, `ReminderRecipients`. Defaults do nothing special (guardian e-mail for reminders).

## Events

Emitted (all in `Classivo\Fees\Events`):

| Class | When | Payload |
|---|---|---|
| `FeePaymentRecorded` | A payment is recorded | paymentId, receiptNo, studentId, amount, date, head, mode, schoolId, branchId, sessionId |
| `FeePaymentReverted` | A receipt is reverted | paymentId, receiptNo, studentId, amount, date, head, revertedBy, reason, schoolId, branchId, sessionId |
| `FeeRefunded` | A refund is issued | refundId, creditNoteNo, feePaymentId, receiptNo, studentId, amount, date, head, mode, reasonCode, restoreDues, issuedBy, schoolId, branchId, sessionId |
| `FeeAccrued` | A late fine is added to a fee line | allocationId, studentId, fineAmount, delta, date, schoolId, branchId, sessionId |

Listened: none found in this package.

## Install

```
composer require classivo/fees
php artisan migrate
```

Register the plugin on your admin panel:

```php
$panel->plugin(\Classivo\Fees\FeesPlugin::make())
```

For portals:

```php
$studentPanel->plugin(\Classivo\Fees\FeesPortalPlugin::student())
$guardianPanel->plugin(\Classivo\Fees\FeesPortalPlugin::guardian())
```

Also needed: `classivo/core` (required) and `barryvdh/laravel-dompdf` (required, for PDFs). Suggested: `classivo/students` (students to charge) and `spatie/laravel-permission` (grant roles). Run `php artisan db:seed --class="Classivo\Fees\Database\Seeders\FeesPermissionSeeder"` after setting `grant_to_roles`.

## Known limitations

- No online payment and no accounting posting. Use events, or install `classivo/payments`.
- Reminders go by e-mail only.
- The reminder and fine commands are not scheduled for you.
- Clearance is off by default and only covers marksheets and admit cards by config.
- TODO: confirm how the clearance check is wired into the exams package.
