# Demo

A public demo runs at https://demo.classivoerp.com. It uses a made-up school with made-up students, parents and staff.

## Logins

The password for every account is `password`.

| Role | Email | Where it goes |
|---|---|---|
| Read-only | viewer@example.com | Admin panel (`/admin`): every screen and report, but nothing can be changed |
| Admin | admin@example.com | Admin panel (`/admin`), full access (for your own local install) |
| Student | student@example.com | Student portal (`/student`) |
| Parent | parent@example.com | Parent portal (`/parent`) |

The student login is linked to the first student in the demo data. The parent login is that student's main guardian.

The read-only login holds only view, report, print and download permissions. On the public demo the demo guard also refuses any save, edit or delete made while signed in with it. The sign-in details are shown on each login page of the public demo.

## Reset

The demo is reset every night. Anything you add or change is removed at the next reset.

## Safeguards

The `demo-guard` package is active only when `APP_ENV=demo`. It blocks destructive edits, limits uploads and shows a banner about the nightly reset.

## Run your own demo

See [Install](/install). The demo school data comes from `DemoSchoolSeeder` in the demo app.
