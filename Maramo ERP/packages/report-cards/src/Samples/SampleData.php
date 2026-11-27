<?php

namespace Classivo\ReportCards\Samples;

use Classivo\ReportCards\DTO\IdCard;
use Classivo\ReportCards\DTO\IdCardSheet;
use Classivo\ReportCards\DTO\Institution;
use Classivo\ReportCards\DTO\MarksheetData;
use Classivo\ReportCards\DTO\StudentInfo;
use Classivo\ReportCards\DTO\SubjectResult;
use Classivo\ReportCards\DTO\TransferCertificateData;
use Classivo\ReportCards\Support\QrCode;

/** Entirely fictional data for demos, docs and tests. */
final class SampleData
{
    public static function institution(): Institution
    {
        return new Institution('Greenfield Public School', 'Main Campus', '12 Example Road, Sample City', '+00 000 000 0000', null, 'Affiliation No. 0000000');
    }

    public static function student(): StudentInfo
    {
        return new StudentInfo('Asha Verma', 'ADM-1001', 'X - A', '7', '14 Mar 2011', 'Rohan Verma', 'Meera Verma', 'Female', '5 Sample Lane', 'B+', '2026-27');
    }

    public static function marksheet(): MarksheetData
    {
        $rows = [
            ['English', 100, 33, 88, 'A2', 9.0],
            ['Hindi', 100, 33, 91, 'A1', 10.0],
            ['Mathematics', 100, 33, 95, 'A1', 10.0],
            ['Science', 100, 33, 84, 'A2', 9.0],
            ['Social Science', 100, 33, 79, 'B1', 8.0],
        ];
        $subjects = array_map(fn ($r) => new SubjectResult($r[0], $r[1], $r[2], $r[3], false, $r[4], $r[5]), $rows);

        return (new MarksheetData(
            self::institution(), self::student(), 'Annual Examination 2026-27', $subjects,
            grade: 'A2', division: 'First', rank: 3, remarks: 'Excellent progress. Keep it up.',
            workingDays: 220, daysPresent: 208,
            coScholastic: ['Art Education' => 'A', 'Health & Physical Education' => 'A', 'Discipline' => 'A'],
            generatedOn: '01 Apr 2027',
        ))->withComputedTotals();
    }

    public static function transferCertificate(): TransferCertificateData
    {
        return new TransferCertificateData(
            self::institution(), self::student(), 'TC0001', '01 Apr 2027', '31 Mar 2027', 'X - A',
            'Family relocation', 'Good', 220, 208, 'Promoted to Class XI', true, 'No remarks.', 'Office Administrator',
        );
    }

    public static function idCards(): IdCardSheet
    {
        $rows = fn (array $m) => array_map(fn ($k, $v) => ['label' => $k, 'value' => $v], array_keys($m), $m);

        return new IdCardSheet(self::institution(), [
            new IdCard('Asha Verma', $rows(['Adm No' => 'ADM-1001', 'Class' => 'X - A', 'DOB' => '14 Mar 2011', 'Blood' => 'B+']), null, 'ADM-1001'),
            new IdCard('Kabir Singh', $rows(['Adm No' => 'ADM-1002', 'Class' => 'IX - B', 'DOB' => '02 Aug 2012', 'Blood' => 'O+']), null, 'ADM-1002'),
        ], '#1e3a8a');
    }

    /** Sample editable certificate template bodies (HTML with {{tags}}). */
    public static function certificateTemplates(): array
    {
        return [
            'generic' => '<h2>CERTIFICATE</h2><p style="text-align:center">Certificate No. {{certificate_no}}</p>'
                .'<p>This is to certify that <strong>{{student_name}}</strong>, Admission No. {{admission_no}}, '
                .'of Class {{class_section}}, is a bonafide student of <strong>{{school_name}}</strong> for the session {{session}}.</p>'
                .'<p>Date: {{date}}</p><p style="text-align:right">{{qr_code}}</p>',
            'cbse' => '<h2>BONAFIDE CERTIFICATE</h2>'
                .'<p>Certified that <strong>{{student_name}}</strong>, son/daughter of Mr. {{father_name}} and Mrs. {{mother_name}}, '
                .'born on {{dob}}, is studying in Class {{class_section}} of this school during {{session}}. '
                .'His/her conduct and character are good.</p><p>Place / Date: {{date}}</p>',
        ];
    }

    /** @return array<string,string> */
    public static function certificateVariables(): array
    {
        return [
            'student_name' => 'Asha Verma', 'admission_no' => 'ADM-1001', 'class_section' => 'X - A',
            'father_name' => 'Rohan Verma', 'mother_name' => 'Meera Verma', 'dob' => '14 Mar 2011',
            'session' => '2026-27', 'school_name' => 'Greenfield Public School', 'date' => '01 Apr 2027',
            'certificate_no' => 'CERT00001',
            'qr_code' => '<img src="'.QrCode::dataUri('ADM-1001').'" style="width:80px;height:80px" alt="QR">',
        ];
    }
}
