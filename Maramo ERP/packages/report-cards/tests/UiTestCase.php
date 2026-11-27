<?php

namespace Classivo\ReportCards\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Exams\ExamsServiceProvider;
use Classivo\Pdf\PdfServiceProvider;
use Classivo\ReportCards\ReportCardsServiceProvider;
use Classivo\ReportCards\Tests\Support\StubUser;
use Classivo\ReportCards\Tests\Support\TestPanelProvider;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\StudentsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class UiTestCase extends Orchestra
{
    use RefreshDatabase;

    protected ClassSection $classSection;

    protected function getPackageProviders($app): array
    {
        return [
            PdfServiceProvider::class, LivewireServiceProvider::class, BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class,
            InfolistsServiceProvider::class, SchemasServiceProvider::class, TablesServiceProvider::class,
            NotificationsServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class,
            CoreServiceProvider::class, StudentsServiceProvider::class, ExamsServiceProvider::class,
            ReportCardsServiceProvider::class, TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('classivo.user_model', StubUser::class);
        $app['config']->set('auth.providers.users.model', StubUser::class);
        $app['config']->set('report-cards.persistence', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        StubUser::$grants = [];

        $school = School::create(['name' => 'Demo School', 'code' => 'DEMO']);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => true, 'is_default' => true]);
        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_current' => true, 'status' => true]);
        config(['classivo.school_id' => $school->id]);
        current_context()->setSchoolId($school->id)->setBranchId($branch->id)->setSessionId($session->id);

        $class = SchoolClass::create(['name' => 'Grade 1', 'numeric_order' => 1]);
        $section = Section::create(['name' => 'A']);
        $this->classSection = ClassSection::create(['class_id' => $class->id, 'section_id' => $section->id]);
    }

    protected function actAs(array $grants): StubUser
    {
        StubUser::$grants = $grants;
        $user = StubUser::create(['name' => 'Admin User', 'email' => 'admin@example.com', 'password' => 'secret']);
        $this->actingAs($user);

        return $user;
    }

    protected function makeStudent(string $first, int $roll): Student
    {
        $student = Student::create(['first_name' => $first, 'last_name' => 'Test', 'admission_no' => 'ADM'.$roll, 'status' => 'active']);
        StudentSessionRecord::create(['student_id' => $student->id, 'class_section_id' => $this->classSection->id, 'roll_no' => (string) $roll]);

        return $student;
    }

    protected function makeSubject(string $name): Subject
    {
        return Subject::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'status' => true]);
    }
}
