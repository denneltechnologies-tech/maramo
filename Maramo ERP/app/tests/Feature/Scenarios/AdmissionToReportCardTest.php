<?php

namespace Tests\Feature\Scenarios;

use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Mark;
use Classivo\FrontOffice\FrontOfficePlugin;
use Classivo\FrontOffice\Models\OnlineAdmission;
use Classivo\FrontOffice\Services\OnlineAdmissionService;
use Classivo\Hostel\HostelPlugin;
use Classivo\Hostel\Models\Hostel;
use Classivo\Hostel\Models\HostelAllocation;
use Classivo\Hostel\Models\HostelRoom;
use Classivo\Hostel\Services\HostelAllocationService;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Models\Student;
use Classivo\Students\Services\AttendanceService;
use Classivo\Transport\Models\Route;
use Classivo\Transport\Models\RouteStoppage;
use Classivo\Transport\Models\Stoppage;
use Classivo\Transport\Models\StudentTransport;
use Classivo\Transport\Models\Vehicle;
use Classivo\Transport\Services\TransportAssignmentService;
use Classivo\Transport\TransportPlugin;
use Livewire\Livewire;

/**
 * One applicant, end to end: public online admission form -> staff approve ->
 * student exists -> transport + hostel -> attendance -> marks -> results ->
 * published -> marksheet PDF.
 */
class AdmissionToReportCardTest extends ScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePackages(FrontOfficePlugin::class, HostelPlugin::class, TransportPlugin::class);
    }

    public function test_applicant_becomes_a_student_with_transport_hostel_attendance_marks_and_a_marksheet(): void
    {
        $this->bootDemo();
        $cs = $this->grade1A();

        // 1. Public apply (no login).
        $this->post('/admission', [
            'first_name' => 'Zephyr', 'last_name' => 'Quillfeather', 'gender' => 'male',
            'date_of_birth' => '2018-05-05', 'class_id' => $cs->class_id,
            'guardian_name' => 'Orla Quillfeather', 'guardian_phone' => '9100000001', 'terms' => '1',
        ])->assertRedirect();

        $application = OnlineAdmission::firstOrFail();
        $this->assertSame('pending', $application->status);
        $this->get('/admission/status?application_no='.$application->application_no)->assertOk()->assertSee($application->application_no);

        // 2. Staff approve (fee marked paid, then converted).
        $service = app(OnlineAdmissionService::class);
        $service->markPaid($application, 'RCPT-1');
        $student = $service->convert($application, $cs->class_id, $cs->section_id);

        $this->assertSame('enrolled', $application->fresh()->status);
        $this->assertSame($student->id, $application->fresh()->converted_student_id);
        $this->assertSame('Orla Quillfeather', $student->primaryGuardian()?->name);
        $this->assertSame($cs->id, Student::findOrFail($student->id)->current_class_section?->id);
        // Approving twice does not admit a second student.
        $this->assertSame($student->id, $service->convert($application->fresh())->id);
        $this->assertSame(31, Student::count());

        // 3. Transport + hostel.
        $route = Route::create(['title' => 'North', 'code' => 'RT-N', 'default_fare' => 500, 'status' => true]);
        $stop = Stoppage::create(['name' => 'Park Gate', 'status' => true]);
        RouteStoppage::create(['route_id' => $route->id, 'stoppage_id' => $stop->id, 'fare' => 650, 'sequence' => 1, 'status' => true]);
        $bus = Vehicle::create(['vehicle_number' => 'BUS-1', 'type' => 'bus', 'capacity' => 30, 'status' => true]);
        $transport = app(TransportAssignmentService::class)->assign($student, ['route_id' => $route->id, 'stoppage_id' => $stop->id, 'vehicle_id' => $bus->id]);
        $this->assertSame('active', $transport->status);
        $this->assertEquals(650.0, (float) $transport->fare);
        $this->assertSame(1, StudentTransport::where('student_id', $student->id)->where('status', 'active')->count());

        $hostel = Hostel::create(['name' => 'Cedar House', 'type' => 'boys', 'status' => true]);
        $room = HostelRoom::create(['hostel_id' => $hostel->id, 'room_no' => '101', 'capacity' => 2, 'cost_per_bed' => 2500, 'status' => true]);
        $bed = app(HostelAllocationService::class)->allocate($student, ['room_id' => $room->id, 'bed_no' => 'B1']);
        $this->assertSame('active', $bed->status);
        $this->assertSame(1, HostelAllocation::where('student_id', $student->id)->where('status', 'active')->count());

        // 4. Attendance for the section (Monday).
        $date = '2030-03-04';
        $roster = app(AttendanceService::class)->roster($cs);
        $this->assertTrue($roster->pluck('id')->contains($student->id), 'the converted student is on the class roster');
        app(AttendanceService::class)->saveDaily($cs, $date, $roster->map(fn ($s) => ['student_id' => $s->id, 'status' => $s->id === $student->id ? 'present' : 'absent'])->all());
        $this->assertSame('present', Attendance::whereDate('date', $date)->where('student_id', $student->id)->value('status'));

        // 5. Exam + marks entered by the admin through the Marks Register UI.
        $this->login('admin@example.com');
        $english = $this->subject('ENG');
        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $exam = Exam::create(['exam_group_id' => $group->id, 'class_id' => $cs->class_id, 'name' => 'Mid Term']);
        ExamSchedule::buildSchedule($exam, $cs->class_id, null, [['subject_id' => $english->id, 'full_marks' => 100, 'pass_marks' => 33]]);

        $page = Livewire::test(MarksRegister::class)
            ->fillForm(['exam_id' => $exam->id, 'class_section_id' => $cs->id, 'subject_id' => $english->id])
            ->call('load');
        $rows = $page->get('data.students');
        $index = collect($rows)->search(fn ($r) => $r['student_id'] === $student->id);
        $this->assertNotFalse($index, 'student is listed in the marks register');
        $page->set("data.students.$index.marks_obtained", 88)->call('save');

        $this->assertEquals(88.0, (float) Mark::where('exam_id', $exam->id)->where('student_id', $student->id)->value('marks_obtained'));

        // 6. Results generated + published through the UI.
        Livewire::test(ExamResults::class)
            ->fillForm(['exam_id' => $exam->id, 'class_section_id' => $cs->id])
            ->call('generate')
            ->call('publish');

        $exam->refresh();
        $this->assertTrue($exam->is_published);
        $result = ExamResult::where('exam_id', $exam->id)->where('student_id', $student->id)->firstOrFail();
        $this->assertEqualsWithDelta(88.0, (float) $result->percentage, 0.01);
        $this->assertSame('pass', $result->result);
        $this->assertTrue((bool) $result->published);

        // Marks are locked once published.
        $this->assertTrue($exam->isLocked());

        // 7. Marksheet PDF download.
        $response = $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => $student->id]));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $this->get(route('report-cards.tabulation', ['exam' => $exam->id, 'classSection' => $cs->id]))->assertOk();
    }
}
