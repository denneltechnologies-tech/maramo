<?php

namespace Database\Seeders;

use App\Models\User;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Classivo\ReportCards\Support\QrCode;
use Classivo\Students\Models\Student;
use Illuminate\Database\Seeder;

class MaramoCertificateSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::query()->first();
        $branch = Branch::query()->first();
        $admin = User::query()->where('email', 'admin@maramobeauty.com')->first();

        if (! $school || ! $branch) {
            return;
        }

        $htmlContent = <<<'HTML'
<div style="font-family:'Georgia', serif; padding:30px; border:8px double #c59b27; background:#fffdfa; text-align:center; position:relative;">
  <div style="border:2px solid #e2caa0; padding:25px;">
    <div style="margin-bottom:15px;">
      <h3 style="margin:0; font-size:14px; letter-spacing:4px; text-transform:uppercase; color:#856404;">Accra, Ghana</h3>
      <h1 style="margin:5px 0 0; font-size:32px; color:#1a1310; letter-spacing:2px; font-weight:bold;">MARAMO BEAUTY INSTITUTE</h1>
      <p style="margin:5px 0 0; font-size:13px; color:#666; font-style:italic;">Center for Professional Cosmetology, Hair & Aesthetic Excellence</p>
    </div>
    
    <div style="margin:20px 0 10px;">
      <span style="font-size:22px; font-style:italic; color:#856404;">Certificate of Professional Competence</span>
      <p style="font-size:13px; text-transform:uppercase; letter-spacing:2px; color:#444; margin-top:5px;">This is to proudly certify that</p>
    </div>

    <h2 style="font-size:28px; color:#1a1310; margin:10px 0; border-bottom:1px solid #c59b27; display:inline-block; padding:0 30px 5px;">{{student_name}}</h2>

    <p style="font-size:14px; color:#333; line-height:1.6; max-width:650px; margin:10px auto;">
      has successfully completed the prescribed curriculum, practical studio masteries, and professional examination standards in
    </p>

    <h3 style="font-size:22px; color:#856404; margin:10px 0 15px; text-transform:uppercase; letter-spacing:1px;">{{class}}</h3>

    <p style="font-size:13px; color:#555;">
      Conferred on <strong>{{date}}</strong> at Accra, Ghana.
    </p>

    <div style="margin-top:40px; display:flex; justify-content:space-between; align-items:flex-end;">
      <div style="text-align:center; width:180px;">
        <div style="border-bottom:1px solid #1a1310; margin-bottom:5px; height:25px;"></div>
        <span style="font-size:11px; font-weight:bold; text-transform:uppercase; color:#444;">Institute Director</span>
      </div>

      <div style="text-align:center;">
        <div style="margin-bottom:5px;">{{qr_code}}</div>
        <span style="font-size:10px; color:#777; font-family:monospace;">Serial: {{certificate_no}}</span>
      </div>

      <div style="text-align:center; width:180px;">
        <div style="border-bottom:1px solid #1a1310; margin-bottom:5px; height:25px;"></div>
        <span style="font-size:11px; font-weight:bold; text-transform:uppercase; color:#444;">Head of Academics</span>
      </div>
    </div>
  </div>
</div>
HTML;

        $template = CertificateTemplate::firstOrCreate([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'name' => 'Official Certificate of Professional Competence',
        ], [
            'type' => 'student',
            'content' => $htmlContent,
            'page_size' => 'a4',
            'orientation' => 'landscape',
            'is_default' => true,
            'status' => true,
        ]);

        // Issue sample certificates for graduated students
        $students = Student::query()->with('schoolClass')->take(2)->get();
        foreach ($students as $index => $student) {
            $certNumber = 'MBI-CERT-' . date('Y') . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $verifyUrl = "http://localhost:8000/api/verify-certificate/{$certNumber}";
            $qrImg = '<img src="' . QrCode::dataUri($verifyUrl) . '" width="64" height="64" alt="Verification QR" />';

            $variables = [
                'student_name' => $student->full_name,
                'admission_no' => $student->admission_no,
                'class' => $student->schoolClass?->name ?? 'Comprehensive Cosmetology',
                'course_name' => $student->schoolClass?->name ?? 'Comprehensive Cosmetology',
                'date' => now()->subDays(5)->format('d F Y'),
                'certificate_no' => $certNumber,
                'qr_code' => $qrImg,
                'school_name' => $school->name,
                'branch_name' => $branch->name,
            ];

            $rendered = str_replace(
                array_map(fn ($k) => '{{' . $k . '}}', array_keys($variables)),
                array_values($variables),
                $htmlContent
            );

            GeneratedCertificate::firstOrCreate([
                'certificate_number' => $certNumber,
            ], [
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'certificate_template_id' => $template->id,
                'student_id' => $student->id,
                'data' => [
                    'variables' => $variables,
                    'html' => $rendered,
                ],
                'issued_by' => $admin?->id,
                'issued_at' => now()->subDays(5),
            ]);

            // Mark this student as completed
            $student->update(['status' => 'completed']);
        }
    }
}
