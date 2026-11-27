# classivo/core

The base of Classivo. It holds the school, campuses, academic years, classes, sections and subjects, and it tells every other package which school, campus and year is active.

## What it does

Core is where you set up the shape of your school. You add your campuses (branches), your academic years, your classes, the sections inside each class, and your subjects.

Core also keeps track of "where you are working". Every record in Classivo is filed under a school, a campus and an academic year. Core makes sure you only see the records for the campus and year you picked.

Small rules that protect your data:

- Only one academic year can be marked as current. Marking a new one clears the old one.
- A campus that still has students or attendance cannot be permanently deleted.
- An academic year that still has students or attendance cannot be permanently deleted.
- A new campus gets default values (currency, time zone, date format, weekend days, language) from the regional preset. The default preset is India. A second preset, generic, is also included.

Core has screens (see below). It also works as a library for the other packages: they use its models, tenancy traits and helper functions.

## Screens

| Screen | What it does | Where in the panel menu |
|---|---|---|
| Branches | Add and edit campuses. Set the default campus, contact details, currency, time zone, weekend days and the admission number prefix, start and digits. | School Setup (first item) |
| Academic Sessions | Add academic years with start and end dates. Mark one as the current session. | School Setup (second item) |
| Classes | Add classes and the sections each class has. | Academics |
| Sections | Add sections (for example A, B) with a capacity. Shows how many classes use each section. | Academics |
| Subjects | Add subjects with a code and a type (Theory or Practical). | Academics |

## Permissions

None. Core does not define any permission strings and does not check permissions itself. The screens are open to whoever can reach the Filament panel. To limit access, add your own Filament policies on the core models.

## Configuration

Publish the file with:

```
php artisan vendor:publish --tag=classivo-config
```

This creates `config/classivo.php`.

| Key | Default | Meaning |
|---|---|---|
| `school_id` | `1` (env `CLASSIVO_SCHOOL_ID`) | The id of the school when the install runs one school. Used by the default resolver, `SingleSchoolResolver`. |
| `user_model` | `App\Models\User` (env `CLASSIVO_USER_MODEL`) | The user model that teachers, "marked by" and similar references point to. |
| `preset` | `india` (env `CLASSIVO_PRESET`) | Which regional preset gives the defaults. Use `generic` or add your own. |
| `presets.india` | country `IN`, board `CBSE`, currency `INR` (symbol ₹), timezone `Asia/Kolkata`, date format `d-m-Y`, session start month `4`, locales `en`, `hi`, weekend days `[0]` (Sunday) | Defaults used for new campuses and sessions. |
| `presets.generic` | country none, board none, currency `USD` (symbol $), timezone `UTC`, date format `Y-m-d`, session start month `9`, locales `en`, weekend days `[0, 6]` | Neutral defaults. |

Developer notes:

- Helpers: `current_school_id()`, `current_branch_id()`, `current_session_id()`, `current_branch()`, `current_session()`, `current_context()`.
- Traits for your own models: `BelongsToTenant`, `BelongsToBranch`, `BelongsToSession` in `Classivo\Core\Concerns`.
- To run more than one school, bind your own class that implements `Classivo\Core\Tenancy\TenantResolver`. It wins over the default.
- The middleware alias `classivo.tenant` reads the chosen campus and year from the web session (`classivo.branch_id`, `classivo.session_id`). If none is chosen, it uses the default campus and the current year.
- In console commands (seeders, queues) the middleware does not run. Reads are not filtered, but new rows are still filled with school, campus and year.
- Models: `School`, `Branch`, `AcademicSession`, `SchoolClass`, `Section`, `ClassSection`, `Subject`, `ClassSubject`, `ClassTeacher`.

## Events

Emitted: none.

Listened: none.

## Install

```
composer require classivo/core
php artisan migrate
```

The service provider `Classivo\Core\CoreServiceProvider` is discovered automatically. Register the Filament plugin on your panel:

```php
->plugin(\Classivo\Core\CorePlugin::make())
```

Add the `classivo.tenant` middleware to the panel so the school, campus and year filters are active:

```php
->middleware(['classivo.tenant'])
```

Requires PHP 8.2 or newer, Laravel 12 components and Filament 4. No other Classivo package is needed.

## Known limitations

- No permission checks (see Permissions).
- The default resolver supports one school per install. Multi-school needs your own `TenantResolver`.
- No events are emitted.
- Class-to-subject and class-to-teacher links (`ClassSubject`, `ClassTeacher`) have models and tables, but this package has no screen for them. TODO: confirm where they are edited.
- Hindi translation files are included (`lang/hi`).
