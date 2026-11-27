# classivo/exams

Exam groups, exams, datesheets, marks entry, grading scale, divisions, result generation with ranks, and publish or unpublish.

## What it does

This package runs your exams from start to finish.

1. Make an exam group (for example Term 1) and the exams inside it.
2. Build the exam schedule for a class: which subjects, on which dates, with full marks and pass marks. Defaults are 100 full and 35 pass.
3. Enter marks in the Marks Register for one class-section and one subject at a time. You can mark a student absent. Marks cannot be negative, not a number, or above the full marks.
4. Optionally split a subject into parts (for example Theory and Practical) with a weight.
5. Generate results for a class-section. The package adds up the marks, works out the percentage, gives a grade and a division, and ranks students. Students with the same total share a rank (1, 2, 2, 4). A student fails if any subject is below pass marks, absent, or has no marks.
6. Publish the result. Once published, marks are locked. Unpublish to unlock them.

Only active students enrolled in the class-section are included in results.

Report cards and marksheets are not made here. See classivo/report-cards.

## Screens

| Screen | What it does | Where in the panel menu |
|---|---|---|
| Exam Group / Type | Add exam groups and their type (Marks, Grade (GPA), or Marks & Grade). | Examinations |
| Exams | List and edit exams. Row actions: Exam Schedule, Marks Register, Results, Publish Result, Unpublish Result. | Examinations |
| Exam Schedule | Build the schedule for an exam and class (subjects, dates, times, room, full and pass marks). Save Schedule. | Examinations |
| Marks Register | Choose exam, class-section and subject, load students, enter marks, Save Marks. Read-only when published. | Examinations |
| Exam Result | Generate Results for a class-section, view totals, rank, grade and division, publish or unpublish. Shows document links when another package provides them. | Examinations |
| Grades / Grading Scale | Add grade bands with a percent range, grade point and "failing grade" switch. | Examinations |
| Marks Distribution | Split a schedule row into components with weights. | Examinations |
| Exam Attendance | Record whether a student was present in an exam schedule row. | Examinations |

## Permissions

Two abilities are checked. They are set in config (`abilities`).

| Permission string | Needed for |
|---|---|
| `exam.enter_marks` | Saving marks in the Marks Register. |
| `exam.publish_result` | The Publish and Unpublish actions on the Exams list and the Exam Result page. |

How they work: if your app has not defined a Gate with the same name, the package defines one. It allows only users who hold the permission of the same name through `spatie/laravel-permission` (`hasPermissionTo`). Guests and users without that API are denied. Nobody gets these by default. Create the two permissions and give them to roles. Or define your own Gates with these names.

No other screen in this package checks permissions.

## Configuration

Publish with:

```
php artisan vendor:publish --tag=classivo-exams-config
php artisan vendor:publish --tag=classivo-exams-lang
```

File: `config/classivo-exams.php`.

| Key | Default | Meaning |
|---|---|---|
| `abilities.enter_marks` | `exam.enter_marks` | Name of the ability that allows marks entry. |
| `abilities.publish_result` | `exam.publish_result` | Name of the ability that allows publish and unpublish. |
| `default_full_marks` | `100` | Full marks pre-filled in the schedule builder. |
| `default_pass_marks` | `35` | Pass marks pre-filled in the schedule builder. |

Optional starter data: `php artisan db:seed --class='Classivo\Exams\Database\Seeders\ExamsDefaultsSeeder'`. It adds a grading scale and divisions for the current school, campus and year. With the India preset the scale is A+ to E (8 bands, grade points 10 to 0). With the generic preset it is A to F. Divisions: Distinction 75, First 60, Second 45, Third 33, Fail 0. Existing rows are not duplicated.

## Events

Emitted (in `Classivo\Exams\Events`):

| Class | When | Payload |
|---|---|---|
| `ExamResultsGenerated` | After results are generated for one class-section | `exam`, `classSection`, `results` (collection of `ExamResult`) |
| `ExamResultsPublished` | After an exam's results are published | `exam` |
| `ExamResultsUnpublished` | After an exam's results are unpublished | `exam` |

Listened: none.

Extension point: bind your own class to `Classivo\Exams\Contracts\ResultDocumentLinks` (method `classLinks(Exam, ClassSection)` returns label to URL). The Exam Result page then shows those links.

## Install

```
composer require classivo/exams
php artisan migrate
```

Needs `classivo/core` and `classivo/students`. Run migrations after those two. Register the plugin:

```php
->plugin(\Classivo\Exams\ExamsPlugin::make())
```

For the permissions, install `spatie/laravel-permission` in your app (or define your own Gates).

## Known limitations

- Permissions are deny by default. Marks entry and publish do nothing until you grant them.
- Only marks entry and publish are guarded. Other screens are open to anyone in the panel.
- The result total uses all schedule rows of the class. A student missing marks in any subject fails.
- No PDF output in this package.
- TODO: roll-number ordering in `ExamResultService::studentsFor` uses a `CAST(... AS UNSIGNED)` SQL expression. Confirm it on your database.
