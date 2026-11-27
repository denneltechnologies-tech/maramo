<?php

namespace Classivo\ReportCards\Adapters;

use Classivo\ReportCards\DTO\IdCard;
use Classivo\ReportCards\DTO\IdCardSheet;
use Classivo\ReportCards\DTO\Institution;
use Classivo\ReportCards\DTO\MarksheetData;
use Classivo\ReportCards\DTO\StudentInfo;
use Classivo\ReportCards\DTO\SubjectResult;
use Classivo\ReportCards\DTO\TransferCertificateData;
use Classivo\ReportCards\Models\IdCardTemplate;
use RuntimeException;

/**
 * OPTIONAL bridge from classivo/students + classivo/exams records to the plain DTOs
 * the renderer consumes. The renderer never imports this class.
 *
 * Records are read through data_get(), so Eloquent models, stdClass objects and arrays
 * all work. Expected attributes:
 *   student: full_name, admission_no, current_roll_no, date_of_birth, gender, blood_group,
 *            current_address, mobile, classSection.name, session.name, guardians[].name/relation
 *   mark:    is_absent, marks_obtained, subject.name, grade.name, examSchedule.full_marks/pass_marks
 *   result:  total_marks, total_full_marks, percentage, grade.name, division.name, rank, result
 */
final class ClassivoAdapter
{
    public function institution(mixed $school, mixed $branch = null): Institution
    {
        return new Institution(
            (string) data_get($school, 'name', ''),
            data_get($branch, 'name'),
            data_get($branch, 'address'),
            data_get($branch, 'phone'),
        );
    }

    public function student(mixed $student): StudentInfo
    {
        $dob = data_get($student, 'date_of_birth');

        return new StudentInfo(
            (string) data_get($student, 'full_name', data_get($student, 'name', '')),
            data_get($student, 'admission_no'),
            data_get($student, 'current_class_section.name', data_get($student, 'classSection.name')),
            $this->str(data_get($student, 'current_roll_no', data_get($student, 'roll_no'))),
            $this->date($dob),
            $this->guardian($student, 'father'),
            $this->guardian($student, 'mother'),
            data_get($student, 'gender'),
            data_get($student, 'current_address'),
            data_get($student, 'blood_group'),
            data_get($student, 'session.name'),
        );
    }

    /**
     * @param  iterable<mixed>|null  $marks  mark rows for this student + exam; when null they are
     *                                       loaded from classivo/exams (which must be installed).
     */
    public function marksheet(mixed $school, mixed $branch, mixed $student, mixed $exam, mixed $result = null, ?iterable $marks = null): MarksheetData
    {
        $marks ??= $this->loadMarks($exam, $student);

        $subjects = [];
        foreach ($marks as $mark) {
            $absent = (bool) data_get($mark, 'is_absent', false);
            $obtained = data_get($mark, 'marks_obtained');
            $subjects[] = new SubjectResult(
                (string) data_get($mark, 'subject.name', '-'),
                (float) data_get($mark, 'examSchedule.full_marks', 0),
                (float) data_get($mark, 'examSchedule.pass_marks', 0),
                $absent || $obtained === null ? null : (float) $obtained,
                $absent,
                data_get($mark, 'grade.name'),
            );
        }

        return new MarksheetData(
            $this->institution($school, $branch),
            $this->student($student),
            (string) data_get($exam, 'name', ''),
            $subjects,
            $result !== null ? (float) data_get($result, 'total_marks') : null,
            $result !== null ? (float) data_get($result, 'total_full_marks') : null,
            $result !== null ? (float) data_get($result, 'percentage') : null,
            data_get($result, 'grade.name'),
            data_get($result, 'division.name'),
            data_get($result, 'rank') !== null ? (int) data_get($result, 'rank') : null,
            data_get($result, 'result'),
        );
    }

    /** @param mixed $tc a persisted TransferCertificate (or any object/array with the same attributes) */
    public function transferCertificate(mixed $school, mixed $branch, mixed $student, mixed $tc, ?string $issuedBy = null): TransferCertificateData
    {
        return new TransferCertificateData(
            $this->institution($school, $branch),
            $this->student($student),
            (string) data_get($tc, 'tc_number', ''),
            $this->date(data_get($tc, 'created_at')),
            $this->date(data_get($tc, 'date_of_leaving')),
            data_get($tc, 'last_class_attended'),
            data_get($tc, 'reason_for_leaving'),
            data_get($tc, 'conduct'),
            data_get($tc, 'total_working_days'),
            data_get($tc, 'days_present'),
            data_get($tc, 'promotion_status'),
            (bool) data_get($tc, 'dues_cleared', false),
            data_get($tc, 'remarks'),
            $issuedBy,
        );
    }

    /**
     * @param  iterable<mixed>  $students
     * @param  mixed  $template  an IdCardTemplate (type=student) or equivalent
     */
    public function idCards(mixed $school, mixed $branch, iterable $students, mixed $template): IdCardSheet
    {
        $labels = (array) ($template instanceof IdCardTemplate ? $template->availableFields() : IdCardTemplate::STUDENT_FIELDS);
        $keys = (array) data_get($template, 'fields', array_keys($labels));

        $cards = [];
        foreach ($students as $student) {
            $info = $this->student($student);
            $values = [
                'admission_no' => $info->admissionNo, 'class_section' => $info->className, 'roll_no' => $info->rollNo,
                'father_name' => $info->fatherName, 'mother_name' => $info->motherName, 'date_of_birth' => $info->dateOfBirth,
                'blood_group' => $info->bloodGroup, 'address' => $info->address,
                'mobile' => data_get($student, 'mobile'), 'session' => $info->session,
            ];
            $rows = [];
            foreach ($keys as $key) {
                if (isset($labels[$key])) {
                    $rows[] = ['label' => $labels[$key], 'value' => (string) ($values[$key] ?? '-')];
                }
            }
            $cards[] = new IdCard($info->name, $rows, null, $info->admissionNo);
        }

        return new IdCardSheet(
            $this->institution($school, $branch), $cards,
            (string) data_get($template, 'header_color', '#1e293b'),
            (string) data_get($template, 'text_color', '#1e293b'),
            (bool) data_get($template, 'show_photo', true),
            (bool) data_get($template, 'show_qr', true),
            (bool) data_get($template, 'show_logo', true),
        );
    }

    /** Variables for certificate templates, from a student record. @return array<string,string> */
    public function certificateVariables(mixed $school, mixed $branch, mixed $student): array
    {
        $s = $this->student($student);
        $class = (string) data_get($student, 'schoolClass.name', '');
        $section = (string) data_get($student, 'section.name', '');

        return [
            'student_name' => $s->name, 'admission_no' => (string) $s->admissionNo, 'roll_no' => (string) $s->rollNo,
            'class' => $class, 'section' => $section, 'class_section' => (string) $s->className,
            'dob' => (string) $s->dateOfBirth, 'gender' => (string) $s->gender,
            'father_name' => (string) $s->fatherName, 'mother_name' => (string) $s->motherName,
            'blood_group' => (string) $s->bloodGroup, 'address' => (string) $s->address,
            'session' => (string) $s->session, 'date' => now()->format('d M Y'),
            'school_name' => (string) data_get($school, 'name', ''), 'branch_name' => (string) data_get($branch, 'name', ''),
        ];
    }

    private function loadMarks(mixed $exam, mixed $student): iterable
    {
        $mark = 'Classivo\\Exams\\Models\\Mark';
        if (! class_exists($mark)) {
            throw new RuntimeException('Pass $marks explicitly or install classivo/exams to load them automatically.');
        }

        return $mark::query()
            ->where('exam_id', data_get($exam, 'id'))
            ->where('student_id', data_get($student, 'id'))
            ->with(['subject', 'grade', 'examSchedule'])
            ->get();
    }

    private function guardian(mixed $student, string $relation): ?string
    {
        foreach ((array) data_get($student, 'guardians', []) as $g) {
            $rel = strtolower((string) data_get($g, 'pivot.relation', data_get($g, 'relation', '')));
            if ($rel === $relation) {
                return data_get($g, 'name');
            }
        }

        return null;
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return $v instanceof \DateTimeInterface ? $v->format('d M Y') : (string) $v;
    }

    private function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
