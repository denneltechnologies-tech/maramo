<?php

namespace Database\Seeders;

use App\Models\User;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentCategory;
use Classivo\Students\Services\AttendanceService;
use Classivo\Students\Services\StudentAdmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A fictional demo school. Every name, phone and email here is made up.
 *
 * Logins (password "password"): admin@example.com (admin panel),
 * viewer@example.com (admin panel, read-only),
 * student@example.com (student portal, linked to the first student),
 * parent@example.com (parent portal, that student's primary guardian).
 */
class DemoSchoolSeeder extends Seeder
{
    private const FIRST_NAMES = [
        ['Ama', 'female'], ['Akosua', 'female'], ['Abena', 'female'], ['Afia', 'female'], ['Adjoa', 'female'],
        ['Yaa', 'female'], ['Esi', 'female'], ['Kofi', 'male'], ['Kwame', 'male'], ['Kwesi', 'male'],
        ['Yaw', 'male'], ['Nana', 'female'], ['Serwaa', 'female'], ['Dentaa', 'female'], ['Comfort', 'female'],
    ];

    private const LAST_NAMES = ['Mensah', 'Appiah', 'Osei', 'Owusu', 'Boateng', 'Agyeman', 'Frimpong', 'Ansah', 'Addo', 'Darko'];

    private const PARENT_NAMES = ['Kwabena', 'Samuel', 'Emmanuel', 'Grace', 'Mercy', 'Patricia', 'Daniel', 'Richard'];

    public function run(): void
    {
        // Demo accounts use the well-known password "password". Refuse to create them
        // on a production install unless explicitly allowed (the docker demo does so).
        if (app()->isProduction() && ! filter_var(env('CLASSIVO_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('DemoSchoolSeeder refuses to run with APP_ENV=production. Set CLASSIVO_ALLOW_DEMO_SEED=true to seed demo data anyway.');
        }

        User::query()->updateOrCreate(
            ['email' => 'admin@maramobeauty.com'],
            ['name' => 'Institute Admin', 'password' => 'password', 'email_verified_at' => now()],
        );

        User::query()->updateOrCreate(
            ['email' => 'viewer@maramobeauty.com'],
            ['name' => 'Institute Viewer', 'password' => 'password', 'email_verified_at' => now()],
        );

        $teacher = User::query()->updateOrCreate(
            ['email' => 'instructor@maramobeauty.com'],
            ['name' => 'Lead Beauty Instructor', 'password' => 'password', 'email_verified_at' => now()],
        );

        $school = School::query()->firstOrCreate(['code' => 'MBI'], [
            'name' => 'Maramo Beauty Institute',
            'email' => 'info@maramobeauty.com',
            'phone' => '+233 54 666 2554',
            'city' => 'Accra',
            'country' => 'GH',
            'board' => 'CTVET / Technical & Vocational',
            'status' => true,
        ]);

        config(['classivo.school_id' => $school->id]);

        $branch = Branch::query()->firstOrCreate(['school_id' => $school->id, 'code' => 'MAIN'], [
            'name' => 'Accra Campus & Academy',
            'city' => 'Accra',
            'principal_name' => 'Lead Director',
            'reg_no_prefix' => 'MBI',
            'reg_no_start' => 1,
            'reg_no_digits' => 4,
            'currency' => 'GHS',
            'currency_symbol' => 'GH₵',
            'is_default' => true,
            'status' => true,
        ]);

        // Training Intakes / Sessions
        $session = AcademicSession::query()->firstOrCreate(['school_id' => $school->id, 'name' => '2026 Academic Year'], [
            'start_date' => Carbon::create(2026, 1, 1),
            'end_date' => Carbon::create(2026, 12, 31),
            'is_current' => true,
            'status' => true,
        ]);

        AcademicSession::query()->firstOrCreate(['school_id' => $school->id, 'name' => '2027 Academic Year'], [
            'start_date' => Carbon::create(2027, 1, 1),
            'end_date' => Carbon::create(2027, 12, 31),
            'is_current' => false,
            'status' => true,
        ]);

        app(CurrentContext::class)->setSchoolId($school->id)->setBranchId($branch->id)->setSessionId($session->id);

        if (SchoolClass::query()->exists()) {
            return; // already seeded
        }

        $sections = collect(['Morning Cohort', 'Weekend Batch'])->map(fn (string $name) => Section::create(['name' => $name, 'capacity' => 25]));
        $subjects = collect([
            ['Sanitation & Client Consultation', 'SAN-101'],
            ['Skin Prep & Foundation Matching', 'SKIN-102'],
            ['Frontal Customization & Plucking', 'WIG-201'],
            ['Bleaching Knots & Styling', 'WIG-202'],
            ['Bridal & Editorial Makeup', 'MUP-301'],
            ['Salon Business & Social Media Marketing', 'BIZ-401'],
        ])->map(fn (array $s) => Subject::create(['name' => $s[0], 'code' => $s[1], 'type' => 'practical']));

        foreach (['Regular Trainee', 'Part-Time / Weekend', 'Sponsored / Scholarship'] as $category) {
            StudentCategory::create(['name' => $category]);
        }

        $courses = [
            'Comprehensive Cosmetology',
            'Wig & Frontal Installation Mastery',
            'Professional Makeup Artistry',
            'Hairstyling & Braiding',
            'Nail Technology & Pedicure',
        ];

        foreach ($courses as $index => $courseName) {
            $class = SchoolClass::create(['name' => $courseName, 'numeric_order' => $index + 1]);
            $class->syncSections($sections->pluck('id')->all());

            ClassSubject::allot($class, null, $subjects->map(fn (Subject $s, int $i): array => [
                'subject_id' => $s->id,
                'teacher_id' => $teacher->id,
                'full_marks' => 100,
                'pass_marks' => 50,
                'sort_order' => $i,
            ])->all());
        }

        $this->admitStudents($sections);
        $this->linkStudentLogin();
        $this->takeAttendance();
    }

    private function admitStudents($sections): void
    {
        $admission = app(StudentAdmissionService::class);
        $categoryId = StudentCategory::query()->where('name', 'Regular Trainee')->value('id')
            ?? StudentCategory::query()->value('id');
        $i = 0;

        foreach (SchoolClass::query()->orderBy('numeric_order')->get() as $class) {
            foreach ($sections as $section) {
                foreach (range(1, 3) as $n) {
                    [$first, $gender] = self::FIRST_NAMES[$i % count(self::FIRST_NAMES)];
                    $last = self::LAST_NAMES[$i % count(self::LAST_NAMES)];
                    $parent = self::PARENT_NAMES[$i % count(self::PARENT_NAMES)].' '.$last;
                    $phone = '+23354'.str_pad((string) (666000 + $i + 1), 6, '0', STR_PAD_LEFT);

                    $admission->admit([
                        'class_id' => $class->id,
                        'section_id' => $section->id,
                        'student_category_id' => $categoryId,
                        'first_name' => $first,
                        'last_name' => $last,
                        'gender' => $gender,
                        'date_of_birth' => now()->subYears(19 + ($i % 8))->subDays($i * 11)->toDateString(),
                        'training_start_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                        'email' => strtolower($first.'.'.$last.$i).'@maramobeauty.com',
                        'city' => 'Accra',
                        'guardian' => ['relation' => 'other', 'name' => $parent, 'phone' => $phone],
                    ]);

                    $i++;
                }
            }
        }
    }

    /** Demo student portal login (student@example.com / password) for the first student. */
    private function linkStudentLogin(): void
    {
        $student = Student::query()->orderBy('id')->first();

        if (! $student) {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'student@example.com'],
            ['name' => $student->full_name, 'password' => 'password', 'email_verified_at' => now()],
        );

        $student->forceFill(['user_id' => $user->id])->save();

        // Parent portal login for the same child's primary guardian.
        if ($guardian = $student->primaryGuardian()) {
            $parent = User::query()->updateOrCreate(
                ['email' => 'parent@example.com'],
                ['name' => $guardian->name, 'password' => 'password', 'email_verified_at' => now()],
            );

            $guardian->forceFill(['user_id' => $parent->id])->save();
        }
    }

    private function takeAttendance(): void
    {
        $service = app(AttendanceService::class);
        $statuses = ['present', 'present', 'present', 'present', 'present', 'present', 'late', 'absent', 'half_day', 'present'];
        mt_srand(2026);

        foreach (ClassSection::query()->get() as $classSection) {
            $roster = $service->roster($classSection);

            for ($d = 10; $d >= 1; $d--) {
                $date = now()->subDays($d);

                if ($service->isWeekend($classSection->branch_id, $date->toDateString())) {
                    continue;
                }

                $service->saveDaily($classSection, $date->toDateString(), $roster->map(fn ($s): array => [
                    'student_id' => $s->id,
                    'status' => $statuses[mt_rand(0, count($statuses) - 1)],
                ])->all(), notify: false);
            }
        }
    }
}
