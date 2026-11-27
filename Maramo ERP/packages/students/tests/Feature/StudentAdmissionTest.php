<?php

namespace Classivo\Students\Tests\Feature;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Students\Events\StudentAdmitted;
use Classivo\Students\Models\Guardian;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentDocument;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\Services\AdmissionNumberGenerator;
use Classivo\Students\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Admission through the shared StudentAdmissionService (ported from
 * StudentAdmissionTest + StudentAdmissionFixesTest; logins, custom fields and
 * fees are out of scope for the open package).
 */
class StudentAdmissionTest extends TestCase
{
    public function test_admission_number_uses_branch_prefix_padding_and_increments(): void
    {
        $this->bootFoundation();

        $this->assertSame('ADM0001', app(AdmissionNumberGenerator::class)->peek());

        $first = $this->admit(['first_name' => 'First']);
        $second = $this->admit(['first_name' => 'Second']);

        $this->assertSame('ADM0001', $first->admission_no);
        $this->assertSame('ADM0002', $second->admission_no);
    }

    public function test_deleted_admission_numbers_are_never_reused(): void
    {
        $this->bootFoundation();

        $first = $this->admit();
        $first->delete();

        $this->assertSame('ADM0002', $this->admit()->admission_no);
    }

    public function test_manual_admission_number_and_auto_roll(): void
    {
        $this->bootFoundation();

        $manual = $this->admit(['admission_no_manual' => true, 'admission_no' => 'OLD-77']);
        $next = $this->admit();

        $this->assertSame('OLD-77', $manual->admission_no);
        $this->assertSame('1', $manual->roll_no);
        $this->assertSame('2', $next->roll_no);
    }

    public function test_admission_creates_one_current_session_record_with_the_class_section(): void
    {
        $this->bootFoundation();

        $student = $this->admit();

        $records = StudentSessionRecord::where('student_id', $student->id)->get();
        $this->assertCount(1, $records);
        $this->assertSame($this->session->id, $records->first()->session_id);
        $this->assertSame($this->classSection->id, $records->first()->class_section_id);
        $this->assertSame('studying', $records->first()->status);
        $this->assertSame($this->classSection->id, $student->class_section_id);
    }

    public function test_admission_fires_the_student_admitted_event(): void
    {
        $this->bootFoundation();
        Event::fake([StudentAdmitted::class]);

        $student = $this->admit();

        Event::assertDispatched(StudentAdmitted::class, fn (StudentAdmitted $e): bool => $e->student->is($student));
    }

    public function test_admitting_as_sibling_shares_the_primary_guardian(): void
    {
        $this->bootFoundation();

        $elder = $this->admit([
            'first_name' => 'Elder',
            'current_address' => '12 Park Road',
            'guardian' => ['relation' => 'father', 'name' => 'Shared Parent', 'phone' => '9000000010'],
        ]);
        $younger = $this->admit(['first_name' => 'Younger', 'sibling_student_id' => $elder->id]);

        $this->assertSame($elder->primaryGuardian()->id, $younger->primaryGuardian()?->id);
        $this->assertSame($elder->id, $younger->admission_sibling_id);
        $this->assertSame('12 Park Road', $younger->current_address);
        $this->assertTrue($elder->siblings()->contains('id', $younger->id));
        $this->assertTrue($younger->siblings()->contains('id', $elder->id));
        $this->assertSame(1, Guardian::count());
    }

    public function test_each_student_keeps_the_primary_guardian_that_was_typed_for_them(): void
    {
        $this->bootFoundation();

        $a = $this->admit(['first_name' => 'Asha', 'guardian' => ['relation' => 'father', 'name' => 'Guardian A', 'phone' => '9000000001']]);
        $b = $this->admit(['first_name' => 'Bina', 'guardian' => ['relation' => 'mother', 'name' => 'Guardian B', 'phone' => '9000000002']]);

        $this->assertSame('Guardian A', $a->primaryGuardian()?->name);
        $this->assertSame('Guardian B', $b->primaryGuardian()?->name);

        foreach ([$a, $b] as $student) {
            $this->assertSame(1, DB::table('student_guardian')->where('student_id', $student->id)->where('is_primary', true)->count());
        }
    }

    public function test_a_typed_guardian_wins_over_the_siblings_guardian(): void
    {
        $this->bootFoundation();

        $elder = $this->admit(['first_name' => 'Elder', 'guardian' => ['relation' => 'father', 'name' => 'Shared Parent', 'phone' => '9000000020']]);
        $younger = $this->admit([
            'first_name' => 'Younger',
            'sibling_student_id' => $elder->id,
            'guardian' => ['relation' => 'other', 'name' => 'Younger Own Guardian', 'phone' => '9000000030'],
        ]);

        $this->assertSame('Younger Own Guardian', $younger->primaryGuardian()?->name);
        $this->assertSame($elder->id, $younger->admission_sibling_id);
        $this->assertTrue($younger->guardians()->where('guardians.id', $elder->primaryGuardian()->id)->exists());
        $this->assertSame('Shared Parent', $elder->fresh()->primaryGuardian()?->name);
    }

    public function test_a_typed_guardian_wins_over_a_linked_existing_guardian(): void
    {
        $this->bootFoundation();

        $first = $this->admit(['first_name' => 'First', 'guardian' => ['relation' => 'father', 'name' => 'Existing Guardian', 'phone' => '9000000040']]);
        $existing = $first->primaryGuardian();

        $second = $this->admit([
            'first_name' => 'Second',
            'link_existing_guardian_id' => $existing->id,
            'guardian' => ['relation' => 'mother', 'name' => 'Second Own Guardian', 'phone' => '9000000050'],
        ]);

        $this->assertSame('Second Own Guardian', $second->primaryGuardian()?->name);
        $this->assertSame('Existing Guardian', $first->fresh()->primaryGuardian()?->name);
        $this->assertSame($first->id, $second->admission_sibling_id);
    }

    public function test_father_block_is_reused_as_primary_when_phones_match(): void
    {
        $this->bootFoundation();

        $student = $this->admit([
            'father' => ['name' => 'Demo Father', 'phone' => '9000000060'],
            'mother' => ['name' => 'Demo Mother', 'phone' => '9000000070'],
            'guardian' => ['relation' => 'father', 'name' => 'Demo Father', 'phone' => '9000000060'],
        ]);

        $this->assertSame(2, $student->guardians()->count());
        $this->assertSame('Demo Father', $student->primaryGuardian()?->name);
    }

    public function test_udise_fields_and_documents_are_persisted(): void
    {
        $this->bootFoundation();

        $student = $this->admit([
            'social_category' => 'obc',
            'minority_group' => 'None',
            'is_bpl' => true,
            'is_ews' => false,
            'is_cwsn' => true,
            'disability_type' => 'Low vision',
            'documents' => [['title' => 'Birth certificate', 'file' => 'students/docs/birth.pdf'], ['title' => '']],
        ])->refresh();

        $this->assertSame('obc', $student->social_category);
        $this->assertTrue($student->is_bpl);
        $this->assertFalse($student->is_ews);
        $this->assertTrue($student->is_cwsn);
        $this->assertSame('Low vision', $student->disability_type);
        $this->assertSame(1, StudentDocument::count());
        $this->assertSame('students/docs/birth.pdf', $student->documents()->first()->file_path);
    }

    public function test_student_persists_across_sessions_but_enrolment_is_per_session(): void
    {
        $this->bootFoundation();

        $student = $this->admit();
        $this->assertSame($this->session->id, $student->session_id);

        $session2 = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2027-2028', 'is_current' => false, 'status' => true]);

        $context = app(CurrentContext::class);
        $context->setSessionId($this->session->id)->markResolved();
        $this->assertNotNull(Student::find($student->id));

        $context->setSessionId($session2->id);
        $this->assertNotNull(Student::find($student->id), 'Students are not session-scoped.');

        $this->assertTrue(Student::enrolledInSession($this->session->id)->whereKey($student->id)->exists());
        $this->assertFalse(Student::enrolledInSession($session2->id)->whereKey($student->id)->exists());

        $context->setSessionId($this->session->id);
        $this->assertSame($this->classSection->id, $student->fresh()->current_class_section?->id);
    }

    public function test_disable_and_re_enable_survive_scratch_attributes(): void
    {
        $this->bootFoundation();
        $student = $this->admit();

        $student->setAttribute('scratch_summary', ['counts' => []]);
        $student->disableStudent(note: 'Relocated');

        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertSame('Relocated', $student->fresh()->disable_note);
        $this->assertNotNull($student->fresh()->disabled_at);

        $student->setAttribute('scratch_summary', []);
        $student->reEnable();

        $this->assertSame('active', $student->fresh()->status);
        $this->assertNull($student->fresh()->disabled_at);
    }
}
