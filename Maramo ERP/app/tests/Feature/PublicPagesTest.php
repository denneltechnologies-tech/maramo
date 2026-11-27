<?php

namespace Tests\Feature;

use Classivo\Cms\CmsPlugin;
use Classivo\Core\Models\SchoolClass;
use Classivo\FrontOffice\FrontOfficePlugin;
use Classivo\FrontOffice\Models\ContactEnquiry;
use Classivo\FrontOffice\Models\Invitee;
use Classivo\FrontOffice\Models\OnlineAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The public, guest-facing pages of the demo app: online admission (apply +
 * status lookup), contact form and event RSVP, against the seeded demo school.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePackages(FrontOfficePlugin::class, CmsPlugin::class);
    }

    public function test_online_admission_apply_and_status(): void
    {
        $this->seed();
        $class = SchoolClass::query()->orderBy('numeric_order')->firstOrFail();

        $this->get('/admission')->assertOk()->assertSee($class->name);

        $this->post('/admission', [
            'first_name' => 'Public',
            'last_name' => 'Applicant',
            'gender' => 'female',
            'date_of_birth' => '2019-05-01',
            'class_id' => $class->id,
            'phone' => '9000012345',
            'email' => 'applicant@example.com',
            'guardian_name' => 'Demo Guardian',
            'guardian_phone' => '9000012346',
            'terms' => '1',
        ])->assertRedirect();

        $application = OnlineAdmission::withoutGlobalScopes()->where('first_name', 'Public')->firstOrFail();

        $this->get('/admission/status?application_no='.$application->application_no)
            ->assertOk()
            ->assertSee('Public');
    }

    public function test_contact_form_creates_an_enquiry(): void
    {
        $this->seed();

        $this->get('/contact-us')->assertOk();
        $this->post('/contact-us', ['name' => 'Website Visitor', 'email' => 'visitor@example.com', 'message' => 'Please call me.'])
            ->assertRedirect();

        $this->assertTrue(ContactEnquiry::withoutGlobalScopes()->where('name', 'Website Visitor')->exists());
    }

    public function test_seeded_invitation_accepts_an_rsvp(): void
    {
        $this->seed();
        $invitee = Invitee::withoutGlobalScopes()->whereNotNull('token')->firstOrFail();

        $this->get('/invitation/'.$invitee->token)->assertOk();
        $this->post('/invitation/'.$invitee->token, ['rsvp_status' => 'yes', 'rsvp_headcount' => 1 + (int) $invitee->guest_count])->assertRedirect();

        $this->assertSame('yes', $invitee->fresh()->rsvp_status);
        $this->get('/invitation/not-a-real-token')->assertNotFound();
    }

    public function test_school_website_home_and_cms_catch_all_do_not_shadow_app_routes(): void
    {
        $this->seed();

        $this->get('/')->assertOk();

        $routes = app('router')->getRoutes();

        foreach ([
            '/admin' => 'filament.admin.pages.dashboard',
            '/student' => 'filament.student.pages.dashboard',
            '/parent' => 'filament.parent.pages.dashboard',
            '/login' => 'login',
            '/admission' => 'classivo-front-office.admission',
            '/contact-us' => 'classivo-front-office.contact',
        ] as $uri => $name) {
            $this->assertSame($name, $routes->match(Request::create($uri))->getName(), $uri);
        }

        $this->assertSame('cms.page', $routes->match(Request::create('/some-page'))->getName());
    }
}
