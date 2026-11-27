<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Timetable\Filament\Pages\ClassTimetable;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Tests\Support\User;
use Classivo\Timetable\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class ClassTimetablePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->foundation();
        Filament::setCurrentPanel('admin');
        $this->actingWith('timetable.view', 'timetable.edit');
    }

    /** Teacher picker lists users with a configured teacher role. */
    private function roleTeacher(string $name): User
    {
        Role::findOrCreate('Teacher', 'web');

        return $this->teacher($name)->assignRole('Teacher');
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'day_of_week' => 1,
            'period_no' => 1,
            'start_time' => '08:00',
            'end_time' => '08:45',
            'subject_id' => null,
            'teacher_id' => null,
            'room' => 'R-201',
            'is_break' => false,
        ], $overrides);
    }

    public function test_plugin_registers_the_page(): void
    {
        $this->assertContains(ClassTimetable::class, Filament::getPanel('admin')->getPages());
    }

    public function test_save_then_clear(): void
    {
        [$cs] = $this->classWithSections('Grade 2', 2, ['A']);
        $teacher = $this->roleTeacher('Teacher Two');
        $subject = $this->subject('English');

        Livewire::test(ClassTimetable::class)
            ->assertOk()
            ->set('data.class_id', $cs->class_id)
            ->set('data.section_id', $cs->section_id)
            ->set('data.periods', [
                $this->row(['subject_id' => $subject->id, 'teacher_id' => $teacher->id]),
                $this->row(['period_no' => 2, 'start_time' => '08:45', 'end_time' => '09:00', 'is_break' => true, 'room' => null]),
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Timetable saved.');

        $timetable = Timetable::where('class_section_id', $cs->id)->firstOrFail();
        $this->assertSame('Grade 2 - A', $timetable->name);
        $this->assertDatabaseHas('timetable_periods', [
            'timetable_id' => $timetable->id,
            'day_of_week' => 1,
            'period_no' => 1,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'room' => 'R-201',
        ]);
        $this->assertSame(2, $timetable->periods()->count());

        // Re-opening the class-section pre-fills the grid.
        $component = Livewire::test(ClassTimetable::class)
            ->set('data.class_id', $cs->class_id)
            ->set('data.section_id', $cs->section_id);
        $this->assertCount(2, $component->get('data.periods'));

        $component->call('clearTimetable')->assertNotified('Timetable cleared.');
        $this->assertSame(0, TimetablePeriod::count());
    }

    public function test_conflicting_save_rolls_back_and_notifies(): void
    {
        [$csA, $csB] = $this->classWithSections('Grade 3', 3, ['A', 'B']);
        $teacher = $this->roleTeacher('Teacher Three');

        Livewire::test(ClassTimetable::class)
            ->set('data.class_id', $csA->class_id)
            ->set('data.section_id', $csA->section_id)
            ->set('data.periods', [$this->row(['teacher_id' => $teacher->id])])
            ->call('save');

        Livewire::test(ClassTimetable::class)
            ->set('data.class_id', $csB->class_id)
            ->set('data.section_id', $csB->section_id)
            ->set('data.periods', [
                $this->row(['day_of_week' => 2, 'teacher_id' => $teacher->id]),
                $this->row(['start_time' => '08:30', 'end_time' => '09:15', 'teacher_id' => $teacher->id]),
            ])
            ->call('save')
            ->assertNotified('Timetable not saved — scheduling conflicts');

        // The whole second save rolled back — including its non-clashing row.
        $this->assertSame(1, TimetablePeriod::count());
    }

    public function test_teacher_picker_lists_only_teacher_role_holders(): void
    {
        $this->roleTeacher('Listed Teacher');
        $this->teacher('Not A Teacher');

        $page = new ClassTimetable;
        $options = (new \ReflectionMethod($page, 'teacherOptions'))->invoke($page);

        $this->assertSame(['Listed Teacher'], array_values($options));
    }

    public function test_save_without_class_section_warns(): void
    {
        Livewire::test(ClassTimetable::class)
            ->call('save')
            ->assertHasErrors();

        $this->assertSame(0, Timetable::count());
    }
}
