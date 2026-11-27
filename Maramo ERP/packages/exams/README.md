# classivo/exams

Examinations for Classivo ERP: exam groups, exams, schedules (datesheet), marks register, grading scale, divisions, optional marks distribution, exam attendance, result generation with ranking, and publish/unpublish (locks marks). Filament plugin: `Classivo\Exams\ExamsPlugin`. No PDF rendering here; report-card packages listen to the exam events and contracts in `src/Contracts`.

## Permissions

Every screen is deny-by-default. `ExamsPermissionSeeder` creates the permissions (`exams.*`, `exam.enter_marks`, `exam.publish_result`) and grants them to the roles in `classivo-exams.grant_to_roles`.

## Who may enter marks

Entering marks needs `exam.enter_marks`, and is further limited to the class-sections the user teaches:

- class teacher of the class-section: every subject;
- subject teacher in the class subject allotment: that subject;
- teacher of a timetable period of the class-section (with `classivo/timetable`): that subject.

The check runs in the Marks Register page and in `ExamMarksService`. Users holding `exams.marks.any_class`, or one of the roles in `classivo-exams.marks_scope.bypass_roles` (Admin and Super Admin by default), may enter marks for any class-section.

MIT licensed.
