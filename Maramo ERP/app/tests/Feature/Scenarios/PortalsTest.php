<?php

namespace Tests\Feature\Scenarios;

use App\Models\User;
use Classivo\OnlineExams\Models\OnlineExam;
use Classivo\OnlineExams\Models\QuestionBank;
use Classivo\OnlineExams\OnlineExamsPlugin;
use Classivo\OnlineExams\Services\OnlineExamService;
use Classivo\Students\Models\Guardian;
use Classivo\Students\Models\Student;
use Classivo\Students\Services\StudentAdmissionService;

/**
 * Online exam taken by two classmates, results published, then the student
 * portal shows the student their own result and the parent portal shows a
 * guardian only their own child.
 */
class PortalsTest extends ScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePackages(OnlineExamsPlugin::class);
    }

    private function exam(): OnlineExam
    {
        $q = QuestionBank::create(['type' => 'mcq_single', 'question_text' => '<p>2+2?</p>', 'marks' => 2, 'negative_marks' => 0, 'status' => true]);
        $q->options()->create(['option_text' => '4', 'is_correct' => true, 'order' => 0]);
        $q->options()->create(['option_text' => '5', 'is_correct' => false, 'order' => 1]);

        $exam = OnlineExam::create([
            'title' => 'Weekly Quiz Alpha', 'class_id' => $this->grade1A()->class_id,
            'duration_minutes' => 30, 'pass_marks' => 1, 'show_result_immediately' => false, 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'status' => true,
        ]);
        app(OnlineExamService::class)->attachManual($exam, [$q->id]);

        return $exam->refresh();
    }

    private function attempt(OnlineExam $exam, Student $student, bool $right)
    {
        $service = app(OnlineExamService::class);
        $attempt = $service->startAttempt($exam, $student);
        $question = $service->questionsForAttempt($attempt)->first();
        $option = $question->options->firstWhere('is_correct', $right)->id;
        $service->saveAnswer($attempt, $question, [$option]);

        return $service->submitAttempt($attempt);
    }

    public function test_student_sees_own_result_and_parent_sees_only_their_child(): void
    {
        $this->bootDemo();
        $cs = $this->grade1A();

        $mine = Student::where('user_id', User::where('email', 'student@example.com')->value('id'))->firstOrFail();
        $other = app(StudentAdmissionService::class)->admit([
            'class_id' => $cs->class_id, 'section_id' => $cs->section_id,
            'first_name' => 'Zephyr', 'last_name' => 'Quillfeather', 'gender' => 'male',
            'guardian' => ['relation' => 'father', 'name' => 'Orla Quillfeather', 'phone' => '9100000002'],
        ]);

        $exam = $this->exam();
        $this->attempt($exam, $mine, true);
        $this->attempt($exam, $other, false);

        // Unpublished: the parent portal shows nothing yet.
        $this->login('parent@example.com');
        $this->get('/parent/child-online-exam-results')->assertOk()->assertDontSee('Weekly Quiz Alpha');

        app(OnlineExamService::class)->setPublished($exam, true);

        // Parent: own child only.
        $this->get('/parent/child-online-exam-results')
            ->assertOk()
            ->assertSee('Weekly Quiz Alpha')
            ->assertSee($mine->full_name)
            ->assertDontSee('Quillfeather');

        // Student portal lists the exam with their result.
        $this->login('student@example.com');
        $this->get('/student/online-exams')->assertOk()->assertSee('Weekly Quiz Alpha');

        // Another guardian sees only their own child.
        $guardian = $other->primaryGuardian();
        $user = User::create(['name' => $guardian->name, 'email' => 'orla@example.com', 'password' => 'password']);
        $user->assignRole('Parent');
        Guardian::whereKey($guardian->id)->update(['user_id' => $user->id]);
        $this->flushSession();
        $this->actingAs($user);
        $this->get('/parent/child-online-exam-results')
            ->assertOk()
            ->assertSee('Quillfeather')
            ->assertDontSee($mine->full_name);
    }

    public function test_panel_boundaries_between_roles(): void
    {
        $this->bootDemo();

        foreach ([
            ['student@example.com', '/admin', 403],
            ['student@example.com', '/parent', 403],
            ['parent@example.com', '/admin', 403],
            ['parent@example.com', '/student', 403],
            ['teacher@example.com', '/admin', 403],
        ] as [$email, $url, $status]) {
            $this->login($email);
            $this->get($url)->assertStatus($status, "$email must not open $url");
        }

        foreach ([['admin@example.com', '/admin'], ['student@example.com', '/student'], ['parent@example.com', '/parent']] as [$email, $url]) {
            $this->login($email);
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [200, 302], "$email should open $url");
            if ($response->isRedirection()) {
                $this->assertStringNotContainsString('/login', (string) $response->headers->get('Location'), "$email was bounced to login from $url");
            }
        }
    }
}
