# Classivo ERP demo app

A Laravel 12 + Filament 4 app that installs the Classivo packages from `../packages` and wires them into three panels: `/admin` (staff), `/student` and `/parent`.

## Which packages are installed

`composer.json` is the only list. The app works with any subset of the Classivo packages: the smallest supported set is

```
classivo/core  classivo/students  classivo/exams  classivo/report-cards
classivo/grading  classivo/timetable  classivo/pdf  classivo/demo-guard
```

Everything else the app does for a package happens only when that package's classes exist. `app/Support/Modules.php` holds the lists:

- admin, student and parent panel plugins;
- demo data seeders (run by `DatabaseSeeder` after the demo school);
- permissions created and granted to the Admin, Teacher, Student and Parent roles (`RolesAndPermissionsSeeder`);
- scheduled commands (`routes/console.php`).

Package routes, migrations, translations and views come from each package's own service provider, which Laravel discovers on install. Without `classivo/cms`, the home page redirects to `/admin`.

## Adding a package

```bash
composer require classivo/fees
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder   # grant its permissions to the demo roles
```

Nothing else changes. To remove one, `composer remove classivo/<name>`.

When you add a new package to the monorepo, add its plugin, seeder, permission list and schedules to `app/Support/Modules.php` once; the entries are skipped wherever the package is not installed.

## Tests

```bash
php artisan test                    # the installed set; tests for missing packages are skipped
../scripts/test-base-set.sh         # throw-away install with only the base set: migrate:fresh --seed + tests
../scripts/test-base-set.sh core students exams report-cards grading timetable pdf demo-guard fees   # any other subset
```

## Demo data

`php artisan migrate:fresh --seed` builds a fictional school. The logins (`admin@example.com`, `teacher@example.com`, `student@example.com`, `parent@example.com`) all use the password `password`, so `DemoSchoolSeeder` refuses to run with `APP_ENV=production` unless `CLASSIVO_ALLOW_DEMO_SEED=true` is set (the Docker setup sets it for its local-only demo).
