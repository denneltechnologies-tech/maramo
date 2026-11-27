<?php

namespace Classivo\ReportCards\Renderer;

/**
 * Variable substitution for editable certificate templates: HTML with {{tag}}
 * (or {tag}) shortcodes. Values are HTML-escaped, except the keys listed in
 * RAW_KEYS (which hold trusted markup such as the QR <img>). Unknown tags are
 * left untouched.
 */
final class CertificateRenderer
{
    public const RAW_KEYS = ['qr_code'];

    /** @return array<string,string> tag => human hint */
    public static function availableVariables(): array
    {
        return [
            'student_name' => "Student's full name",
            'admission_no' => 'Admission number',
            'roll_no' => 'Roll number',
            'class' => 'Class name',
            'section' => 'Section name',
            'class_section' => 'Class - Section',
            'dob' => 'Date of birth',
            'gender' => 'Gender',
            'father_name' => "Father's name",
            'mother_name' => "Mother's name",
            'blood_group' => 'Blood group',
            'address' => 'Current address',
            'staff_name' => "Staff member's full name",
            'employee_id' => 'Employee / staff ID',
            'designation' => 'Designation',
            'department' => 'Department',
            'session' => 'Academic session',
            'date' => "Today's date",
            'certificate_no' => 'Certificate number',
            'school_name' => 'School / institute name',
            'branch_name' => 'Branch name',
            'qr_code' => 'QR image of the identity number',
            'course_name' => 'Course title',
            'completion_date' => 'Course completion date',
        ];
    }

    /** @param array<string,string|null> $variables */
    public function substitute(string $content, array $variables): string
    {
        $variables = array_change_key_case($variables, CASE_LOWER);

        return (string) preg_replace_callback(
            '/\{\{?\s*([a-zA-Z_]+)\s*\}?\}/',
            function (array $m) use ($variables): string {
                $key = strtolower($m[1]);
                if (! array_key_exists($key, $variables)) {
                    return $m[0];
                }
                $value = (string) $variables[$key];

                return in_array($key, self::RAW_KEYS, true) ? $value : e($value);
            },
            $content,
        );
    }
}
