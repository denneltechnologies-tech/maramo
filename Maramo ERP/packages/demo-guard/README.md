# classivo/demo-guard

Safeguards for a public Classivo ERP demo: blocks destructive edits, caps uploads and shows a nightly-reset banner. Active only when APP_ENV=demo.

## Installation and setup

```json
"repositories": [ { "type": "path", "url": "packages/demo-guard", "options": { "symlink": true } } ],
"require": { "classivo/demo-guard": "*" }
```

Auto-discovered. It does nothing unless `APP_ENV=demo` (or `DEMO_GUARD_ENABLED=true`). No panel or package edits needed.

When active it: blocks changing a user's email/password and deleting users; blocks editing/deleting roles and permissions; makes payment credentials, notification (mail/SMS) settings and the school record read-only; rejects uploads over 2 MB / 3 files; shows a banner on every Filament panel (admin, student, parent) and login page.
Artisan, seeders and tinker are exempt (`allow_console`), so the nightly `migrate:fresh --seed` works. Publish/override `config/demo-guard.php` to change limits or guarded models; classes that are not installed are skipped.
Violations raise HTTP 403 (`DemoRestricted`) with a translated message.

MIT licensed.
