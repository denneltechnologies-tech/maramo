<?php

namespace Classivo\Students\Tests\Feature;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Filament\Pages\StudentPromotion;
use Classivo\Students\Filament\Pages\TakeAttendance;
use Classivo\Students\Filament\Pages\TakeSubjectAttendance;
use Classivo\Students\Filament\Resources\GuardianResource;
use Classivo\Students\Filament\Resources\StudentCategoryResource;
use Classivo\Students\Filament\Resources\StudentResource;
use Classivo\Students\Filament\Resources\StudentResource\Pages\ListStudents;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\Support\StudentsPermissions;
use Classivo\Students\Tests\Support\StubUser;
use Classivo\Students\Tests\Support\TestPanelProvider;
use Classivo\Students\Tests\TestCase;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

class AuthorizationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', StubUser::class);
    }

    /** @param  list<string>  $granted */
    private function signIn(array $granted = []): StubUser
    {
        static $n = 0;
        $n++;

        foreach (StudentsPermissions::all() as $name) {
            Gate::define($name, fn ($user) => in_array($name, $granted, true));
        }

        $user = StubUser::create(['name' => 'User '.$n, 'email' => "user{$n}@example.test", 'password' => 'secret']);
        $this->actingAs($user);

        return $user;
    }

    public function test_user_without_permission_gets_403_on_create_and_edit_urls(): void
    {
        $this->bootFoundation();
        $student = $this->admit();
        $this->signIn();

        $this->get(StudentResource::getUrl('index'))->assertForbidden();
        $this->get(StudentResource::getUrl('create'))->assertForbidden();
        $this->get(StudentResource::getUrl('edit', ['record' => $student]))->assertForbidden();
        $this->get(StudentResource::getUrl('view', ['record' => $student]))->assertForbidden();
        $this->get(GuardianResource::getUrl('index'))->assertForbidden();
        $this->get(StudentCategoryResource::getUrl('index'))->assertForbidden();
    }

    public function test_view_permission_alone_does_not_open_create_or_edit(): void
    {
        $this->bootFoundation();
        $student = $this->admit();
        $this->signIn(['students.students.view']);

        $this->get(StudentResource::getUrl('index'))->assertOk();
        $this->get(StudentResource::getUrl('create'))->assertForbidden();
        $this->get(StudentResource::getUrl('edit', ['record' => $student]))->assertForbidden();
        $this->assertFalse(StudentResource::canDelete($student));
        Livewire::test(ListStudents::class)
            ->assertTableActionHidden('edit', $student)
            ->assertTableActionHidden('disable', $student);
        $this->assertFalse(GuardianResource::canViewAny(), 'no leakage across resources');
    }

    public function test_promotion_and_attendance_pages_are_gated(): void
    {
        $this->bootFoundation();
        $this->signIn();

        $this->assertFalse(StudentPromotion::canAccess());
        $this->assertFalse(TakeAttendance::canAccess());
        $this->assertFalse(TakeSubjectAttendance::canAccess());
        $this->get(StudentPromotion::getUrl())->assertForbidden();
        $this->get(TakeAttendance::getUrl())->assertForbidden();
        Livewire::test(StudentPromotion::class)->assertForbidden();

        $this->signIn([StudentsPermissions::PROMOTE]);
        $this->assertTrue(StudentPromotion::canAccess());
        $this->assertFalse(TakeAttendance::canAccess());
    }

    public function test_promotion_ignores_students_injected_from_another_class(): void
    {
        $this->bootFoundation();
        $mine = $this->admit(['first_name' => 'Mine']);
        $otherSection = $this->classSectionFor($this->session->id, 'Grade 3', 3, 'C');
        $other = $this->admit(['first_name' => 'Other', 'class_id' => $otherSection->class_id, 'section_id' => $otherSection->section_id]);
        $session2 = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2027-2028', 'is_current' => false, 'status' => true]);
        $target = $this->classSectionFor($session2->id, 'Grade 2', 2, 'A');
        $this->signIn([StudentsPermissions::PROMOTE]);

        Livewire::test(StudentPromotion::class)
            ->set('data.class_section_id', $this->classSection->id)
            ->set('data.next_session_id', $session2->id)
            ->set('data.next_class_section_id', $target->id)
            ->set('data.students', [
                ['student_id' => $mine->id, 'label' => 'Mine', 'result' => 'pass', 'action' => 'continue', 'new_roll' => '1'],
                ['student_id' => $other->id, 'label' => 'Other', 'result' => 'pass', 'action' => 'continue', 'new_roll' => '2'],
            ])
            ->call('promote');

        $records = StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('session_id', $session2->id);
        $this->assertTrue((clone $records)->where('student_id', $mine->id)->exists());
        $this->assertFalse((clone $records)->where('student_id', $other->id)->exists());
    }
}
