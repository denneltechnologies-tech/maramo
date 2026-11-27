# classivo/timetable

Class routines with clash checks, and a generator that builds a clash-free timetable for the whole school.

## What it does

- Build the weekly routine for a class-section by hand. Pick the day, period, start and end time, subject, teacher and room. You can mark a row as a break.
- The package stops clashes when you save. A teacher cannot be in two class-sections at the same time. A class-section cannot have two overlapping periods. An end time must be after the start time. You see a message saying who clashed and where.
- Tell the package how many periods each subject needs each week, and which room type it needs (for example a lab). Set this for a whole class, or override it for one section.
- Add shared rooms (for example two labs). The generator books them for subjects that need them.
- Let the generator build timetables for the class-sections you choose. It shows a preview first, with reasons if something could not be placed. Nothing is saved until you press Save.
- The generator tries to avoid the same subject twice in a day. It never puts a teacher in two places at once, and caps how many periods in a row a teacher teaches (default 4).
- Timetables of class-sections you do not regenerate are kept, and their teachers count as busy.

The generator itself is plain PHP (`Classivo\Timetable\Generator\*`) and can be used without Laravel.

## Screens

| Screen | What it does | Where in the panel menu |
|---|---|---|
| Class Timetable | Choose a class and section, edit the weekly routine, Save, or Clear Timetable. | Academics (first) |
| Timetable Requirements | Set periods per week and room type for each subject, and list shared rooms. | Academics (second) |
| Generate Timetable | Choose class-sections, days, max periods in a row, max periods of a subject per day, seed and time limit. Preview the result, then Save timetables. | Academics (third) |

## Permissions

Three abilities, set in config (`abilities`). Nobody gets them by default.

| Permission string | Needed for |
|---|---|
| `timetable.view` | Opening the Class Timetable page. |
| `timetable.edit` | Saving or clearing a class timetable, and opening the Timetable Requirements page. |
| `timetable.generate` | Opening the Generate Timetable page and saving its result. |

How they work: if your app has not defined a Gate with the same name, the package defines one. It allows only users who hold the permission of the same name through `spatie/laravel-permission` (`hasPermissionTo`). Guests and users without that API are denied. Create the permissions and give them to roles, or define your own Gates with these names. A page you cannot access is hidden from the menu.

## Configuration

Publish with:

```
php artisan vendor:publish --tag=classivo-timetable-config
php artisan vendor:publish --tag=classivo-timetable-lang
```

File: `config/classivo-timetable.php`.

| Key | Default | Meaning |
|---|---|---|
| `abilities.view` | `timetable.view` | Ability name for viewing. |
| `abilities.edit` | `timetable.edit` | Ability name for editing. |
| `abilities.generate` | `timetable.generate` | Ability name for generating. |
| `teacher_roles` | `['Teacher', 'Class Teacher']` | Role names listed in the teacher picker. Used only when the user model has a `roles` relation. Otherwise every user is listed. |
| `generator.days` | `null` | Weekdays to schedule (0 = Sunday to 6 = Saturday). `null` means every day except the weekend days of the active core preset (India: Monday to Saturday). |
| `generator.periods` | 9 rows from 08:00 to 13:40, 40 minutes each, with a break row 10:40 to 11:00 | The daily bell schedule. Rows with `'break' => true` become break rows and never get a lesson. `period_no` is the position in the list. |
| `generator.default_weekly_periods` | `5` | Periods per week for a subject when no count is given. |
| `generator.max_consecutive` | `4` | Most back-to-back periods a teacher may teach. `null` means no cap. |
| `generator.max_same_subject_per_day` | `null` | Most periods of one subject per class per day. `null` means no cap (twice a day is still avoided where possible). |
| `generator.seed` | `1` | Same seed and same input give the same timetable. |
| `generator.time_limit_seconds` | `10` | Time budget for the solver. When it runs out you get a partial timetable. |

Developer use of the generator:

```php
$out = app(\Classivo\Timetable\Actions\GenerateTimetables::class)->handle(
    weeklyPeriods: [$mathsId => 6],
);
$out->saved();
```

Arguments override the database, and the database overrides config. Other arguments: `classWeeklyPeriods`, `teacherUnavailable`, `subjectRoomTypes`, `rooms`. The generator reads class-sections, `class_subjects` and `timetable_subject_requirements` (a section row overrides a class-wide row), and the active `timetable_rooms` of the current campus and year.

## Events

Emitted: none.

Listened: none.

## Install

```
composer require classivo/timetable
php artisan migrate
```

Needs `classivo/core`. Run migrations after core, because `teacher_id` points at the `users` table. Tables: `timetables`, `timetable_periods`, `timetable_subject_requirements`, `timetable_rooms`. Register the plugin on a panel that has the `classivo.tenant` middleware:

```php
->plugin(\Classivo\Timetable\TimetablePlugin::make())
```

Install `spatie/laravel-permission` for the default permission check, or define your own Gates.

## Known limitations

- Nobody can open any screen until the three permissions are granted.
- No print or PDF of a routine.
- No "my timetable" pages for teachers, students or parents.
- If no clash-free timetable exists, or the time limit is reached, the generator reports the reasons and saves nothing for an unsolved school. See the preview status.
- Timetable subjects come from the class-subject links in classivo/core. TODO: confirm which screen edits those links.
