<?php

namespace Classivo\ReportCards\Tests;

use Classivo\Core\Models\School;
use Classivo\Exams\Contracts\ResultDocumentLinks;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Services\ExamResultService;
use Classivo\ReportCards\Filament\Resources\CertificateTemplates\Pages\ManageCertificateTemplates;
use Classivo\ReportCards\Filament\Resources\GeneratedCertificates\Pages\ManageGeneratedCertificates;
use Classivo\ReportCards\Filament\Resources\IdCardTemplates\Pages\ManageIdCardTemplates;
use Classivo\ReportCards\Filament\Resources\ReportCardTemplates\Pages\ManageReportCardTemplates;
use Classivo\ReportCards\Filament\Resources\TransferCertificates\Pages\ManageTransferCertificates;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Classivo\ReportCards\Models\IdCardTemplate;
use Classivo\ReportCards\Models\ReportCardTemplate;
use Classivo\ReportCards\Models\TransferCertificate;
use Classivo\ReportCards\Services\ResultDocuments;
use Classivo\ReportCards\Support\Permissions;
use Classivo\Students\Models\Student;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class UiTest extends UiTestCase
{
    public function test_permission_list_is_complete(): void
    {
        $this->assertContains('report_card_templates.view', Permissions::all());
        $this->assertContains('marksheets.download', Permissions::all());
        $this->assertCount(24, Permissions::all());
    }

    public function test_deny_by_default(): void
    {
        $this->actAs([]);
        foreach ([ManageReportCardTemplates::class, ManageCertificateTemplates::class, ManageIdCardTemplates::class,
            ManageGeneratedCertificates::class, ManageTransferCertificates::class] as $page) {
            Livewire::test($page)->assertForbidden();
        }
    }

    public function test_template_resources_create_records(): void
    {
        $this->actAs(['report_card_templates.view', 'report_card_templates.create', 'certificate_templates.view',
            'certificate_templates.create', 'id_card_templates.view', 'id_card_templates.create']);

        Livewire::test(ManageReportCardTemplates::class)->assertOk()
            ->callAction('create', ['name' => 'CBSE layout', 'type' => 'report_card', 'layout' => 'cbse', 'page_size' => 'a4', 'orientation' => 'portrait', 'status' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('cbse', ReportCardTemplate::first()->layout);

        Livewire::test(ManageCertificateTemplates::class)->assertOk()
            ->callAction('create', ['name' => 'Bonafide', 'type' => 'student', 'content' => '<p>{{student_name}}</p>', 'page_size' => 'a4', 'orientation' => 'portrait', 'status' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(1, CertificateTemplate::count());

        Livewire::test(ManageIdCardTemplates::class)->assertOk()
            ->callAction('create', ['name' => 'Student card', 'type' => 'student', 'fields' => ['admission_no'], 'orientation' => 'landscape', 'header_color' => '#112233'])
            ->assertHasNoActionErrors();
        $this->assertSame(['admission_no'], IdCardTemplate::first()->fields);

        // view-only user cannot create
        $this->actAs(['report_card_templates.view']);
        Livewire::test(ManageReportCardTemplates::class)->assertActionHidden('create');
    }

    public function test_issue_and_download_certificate(): void
    {
        Storage::fake('local');
        $this->actAs(['generated_certificates.view', 'generated_certificates.issue', 'generated_certificates.download']);
        $student = $this->makeStudent('Asha', 1);
        $template = CertificateTemplate::create(['name' => 'Bonafide', 'content' => '<p>{{student_name}} {{certificate_no}}</p>']);

        Livewire::test(ManageGeneratedCertificates::class)
            ->callAction('issue', ['certificate_template_id' => $template->id, 'student_id' => $student->id])
            ->assertHasNoActionErrors();

        $cert = GeneratedCertificate::firstOrFail();
        $this->assertSame('CERT00001', $cert->certificate_number);
        $this->assertStringContainsString('Asha Test', $cert->renderedHtml());

        Livewire::test(ManageGeneratedCertificates::class)
            ->callTableAction('download', $cert)->assertFileDownloaded();
        $this->assertNotNull($cert->fresh()->pdf_path);
    }

    public function test_issue_hidden_without_permission(): void
    {
        $this->actAs(['generated_certificates.view']);
        Livewire::test(ManageGeneratedCertificates::class)->assertActionHidden('issue');
    }

    public function test_transfer_certificate_create_and_download(): void
    {
        $this->actAs(['transfer_certificates.view', 'transfer_certificates.create', 'transfer_certificates.download']);
        $student = $this->makeStudent('Kabir', 2);

        Livewire::test(ManageTransferCertificates::class)
            ->callAction('create', ['student_id' => $student->id, 'reason_for_leaving' => 'Relocation', 'dues_cleared' => true])
            ->assertHasNoActionErrors();

        $tc = TransferCertificate::firstOrFail();
        $this->assertSame('TC0001', $tc->tc_number);

        Livewire::test(ManageTransferCertificates::class)->callTableAction('download', $tc)->assertFileDownloaded();
    }

    private function seedResults(): Exam
    {
        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $exam = Exam::create(['exam_group_id' => $group->id, 'name' => 'Mid Term']);
        $english = $this->makeSubject('English');
        ExamSchedule::buildSchedule($exam, $this->classSection->class_id, null, [['subject_id' => $english->id, 'full_marks' => 100, 'pass_marks' => 35]]);
        $a = $this->makeStudent('Asha', 1);
        $b = $this->makeStudent('Kabir', 2);
        app(ExamMarksService::class)->save($exam, $this->classSection, $english, [
            ['student_id' => $a->id, 'marks_obtained' => 90], ['student_id' => $b->id, 'marks_obtained' => 60],
        ]);
        app(ExamResultService::class)->generate($exam, $this->classSection);

        return $exam;
    }

    public function test_result_links_bound_and_permission_gated(): void
    {
        $exam = $this->seedResults();

        $this->actAs([]);
        $this->assertSame([], app(ResultDocumentLinks::class)->classLinks($exam, $this->classSection));

        $this->actAs(['marksheets.download']);
        $links = app(ResultDocumentLinks::class)->classLinks($exam, $this->classSection);
        $this->assertCount(2, $links);
        foreach ($links as $url) {
            $this->assertStringContainsString('/report-cards/exams/', $url);
        }
    }

    public function test_result_document_routes_produce_pdfs(): void
    {
        $exam = $this->seedResults();
        $student = Student::where('admission_no', 'ADM1')->first();

        $this->actAs([]);
        $this->get(route('report-cards.tabulation', ['exam' => $exam->id, 'classSection' => $this->classSection->id]))->assertForbidden();

        $this->actAs(['marksheets.download']);
        $tab = $this->get(route('report-cards.tabulation', ['exam' => $exam->id, 'classSection' => $this->classSection->id]));
        $tab->assertOk();
        $this->assertStringStartsWith('%PDF-', $tab->getContent());

        $ms = $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => $student->id]));
        $ms->assertOk();
        $this->assertStringStartsWith('%PDF-', $ms->getContent());

        $zip = $this->get(route('report-cards.marksheets', ['exam' => $exam->id, 'classSection' => $this->classSection->id]));
        $zip->assertOk();
    }

    public function test_result_document_routes_refuse_another_schools_records(): void
    {
        $exam = $this->seedResults();
        $student = Student::where('admission_no', 'ADM1')->first();
        $other = School::create(['name' => 'Second School', 'code' => 'SEC']);

        // Move the exam, the student and the class-section to the other school.
        foreach ([$exam, $student, $this->classSection] as $model) {
            $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['school_id' => $other->id]);
        }

        $this->actAs(['marksheets.download']);
        // A real request starts with the scopes off until ResolveTenantContext runs.
        current_context()->markResolved(false);

        $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => $student->id]))->assertNotFound();
        $this->get(route('report-cards.tabulation', ['exam' => $exam->id, 'classSection' => $this->classSection->id]))->assertNotFound();
        $this->get(route('report-cards.marksheets', ['exam' => $exam->id, 'classSection' => $this->classSection->id]))->assertNotFound();
    }

    public function test_marksheet_refuses_a_student_who_did_not_sit_the_exam(): void
    {
        $exam = $this->seedResults();
        $stranger = Student::create(['first_name' => 'Not', 'last_name' => 'Here', 'admission_no' => 'ADM99', 'status' => 'active']);

        $this->actAs(['marksheets.download']);
        $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => $stranger->id]))->assertNotFound();
    }

    public function test_marksheets_zip_leaves_no_stray_temp_file(): void
    {
        $exam = $this->seedResults();
        $before = glob(sys_get_temp_dir().'/rc*') ?: [];

        $zip = app(ResultDocuments::class)->marksheetsZip($exam, $this->classSection);

        $new = array_values(array_diff(glob(sys_get_temp_dir().'/rc*') ?: [], $before));
        $this->assertSame([basename($zip)], array_map('basename', $new));
        @unlink($zip);
    }
}
