# classivo/students

Student admission, parents and guardians, categories, daily and subject attendance, and year-end promotion.

## What it does

This package keeps the record of every student in your school.

- Admit a student with one form. The form takes personal details, class and section, address, and parent or guardian details. Father, mother and guardian can be entered together.
- The admission number is made for you from the campus prefix, start number and digit count (set in classivo/core Branches). You can switch on "Enter admission no. manually". Numbers are never reused, even after a student is deleted.
- Roll numbers are suggested as the next free number in the class-section.
- Siblings can share a guardian record.
- Track India-specific details such as UDISE+ fields, social category, BPL, EWS, CWSN and RTE admission.
- Take daily attendance for a class-section. If the date is a weekend for that campus, rows start as Holiday. If attendance was already taken for the date, it opens for editing.
- Take attendance by subject and period.
- Promote students to the next academic year. For each student pick Pass or Fail, and Continue or Leave. You can set a new roll number.
- Disable a student (with a note) and re-enable later. A student with attendance history cannot be permanently deleted. Use disable or soft delete.

## Screens

| Screen | What it does | Where in the panel menu |
|---|---|---|
| Students | List, search, view, add and edit students. Filter by class and status. Disable or re-enable a student. The student page shows this month's attendance. | Students |
| Guardians | Add and edit parents and guardians. | Students |
| Student Categories | Add categories (name, description) and see how many students use each. | Students |
| Student Promotion | Move a class-section's students into a class-section of another academic year. | Students |
| Student Attendance | Mark daily attendance (Present, Absent, Late, Half Day, Holiday, Leave) for a class-section, with a remark. Has "Mark all" shortcuts. | Attendance |
| Subject Attendance | Mark attendance for one subject and period of a class-section. | Same group as Student Attendance (the page extends it) |

## Permissions

None. The package does not define permission strings and does not check permissions itself. Screens are open to anyone who can reach the Filament panel. To limit access, add Filament policies on the models in your app.

## Configuration

This package has no config file and nothing to publish. Admission number settings come from the campus record (`reg_no_prefix` default `ADM`, `reg_no_start` default `1`, `reg_no_digits` default `4`). Weekend days come from the campus too. Both are set in classivo/core.

Translations are loaded under the `classivo-students` namespace (English and Hindi included).

Services you can use in code (all singletons): `StudentAdmissionService::admit()`, `AdmissionNumberGenerator`, `AttendanceService`, `StudentPromotionService::promoteStudent()`.

## Events

Emitted (all in `Classivo\Students\Events`):

| Class | When | Payload |
|---|---|---|
| `StudentAdmitted` | After a student is admitted through `StudentAdmissionService::admit()` | `student` (Student), `data` (the form data array) |
| `StudentPromoted` | After a student is promoted or marked as leaving | `student`, `fromSessionId`, `toSessionId` (null when leaving), `record` (the new `StudentSessionRecord`, null when leaving), `carryForwardFees` (bool) |
| `StudentsMarkedAbsent` | After daily attendance is saved with at least one Absent row (unless the caller turns notify off) | `classSection`, `date`, `studentIds` (unique ids) |

Listened: none.

Other packages (for example fees or notifications) can listen to these events. The "Carry forward dues" switch on the promotion screen only records the request and sets the flag on `StudentPromoted`. A fees package must act on it.

## Install

```
composer require classivo/students
php artisan migrate
```

This also needs `classivo/core`. Register the plugin on your panel:

```php
->plugin(\Classivo\Students\StudentsPlugin::make())
```

Core must be set up first (see classivo/core), including the `classivo.tenant` middleware, an academic session and at least one class with sections.

## Known limitations

- No permission checks (see Permissions).
- Promotion needs the next year's class-sections to exist. If not, it stops with a message asking you to roll over the class structure first.
- Promotion changes the student's own class, section and roll fields to the new year. It also keeps a record per year in `student_session_records`.
- No dues are moved by this package. See Events.
- No built-in import, export or ID/report documents. TODO: confirm whether bulk import exists elsewhere.
- Document uploads are handled by `StudentAdmissionService` (`student_documents` table). TODO: confirm a screen for viewing documents.
