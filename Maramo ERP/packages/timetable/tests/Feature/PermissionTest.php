<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Timetable\Filament\Pages\ClassTimetable;
use Classivo\Timetable\Filament\Pages\GenerateTimetable;
use Classivo\Timetable\Filament\Pages\TimetableRequirements;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Support\Authorize;
use Classivo\Timetable\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class PermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->foundation();
        Filament::setCurrentPanel('admin');
    }

    public function test_denied_by_default_for_guests_and_users_without_permission(): void
    {
        foreach (['view', 'edit', 'generate'] as $key) {
            $this->assertFalse(Authorize::can($key), "guest {$key}");
        }

        $this->actingWith();

        foreach (['view', 'edit', 'generate'] as $key) {
            $this->assertFalse(Authorize::can($key), "user {$key}");
        }
        $this->assertFalse(ClassTimetable::canAccess());
        $this->assertFalse(TimetableRequirements::canAccess());
        $this->assertFalse(GenerateTimetable::canAccess());

        Livewire::test(ClassTimetable::class)->assertForbidden();
        Livewire::test(TimetableRequirements::class)->assertForbidden();
        Livewire::test(GenerateTimetable::class)->assertForbidden();
    }

    public function test_each_permission_opens_only_its_own_screen(): void
    {
        $this->actingWith('timetable.view');
        $this->assertTrue(ClassTimetable::canAccess());
        $this->assertFalse(TimetableRequirements::canAccess());
        $this->assertFalse(GenerateTimetable::canAccess());

        $this->actingWith('timetable.edit');
        $this->assertFalse(ClassTimetable::canAccess());
        $this->assertTrue(TimetableRequirements::canAccess());

        $this->actingWith('timetable.generate');
        $this->assertTrue(GenerateTimetable::canAccess());
        $this->assertFalse(TimetableRequirements::canAccess());
    }

    public function test_view_only_user_cannot_save_the_class_timetable(): void
    {
        [$cs] = $this->classWithSections('Grade 1', 1, ['A']);
        $this->actingWith('timetable.view');

        Livewire::test(ClassTimetable::class)
            ->assertOk()
            ->set('data.class_id', $cs->class_id)
            ->set('data.section_id', $cs->section_id)
            ->set('data.periods', [[
                'day_of_week' => 1, 'period_no' => 1, 'start_time' => '08:00', 'end_time' => '08:45',
                'subject_id' => null, 'teacher_id' => null, 'room' => null, 'is_break' => false,
            ]])
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Timetable::count());
    }

    public function test_missing_permission_row_is_denied_not_an_error(): void
    {
        $this->actingWith();

        $this->assertFalse(Gate::allows('timetable.generate'));
    }

    public function test_host_defined_gate_wins(): void
    {
        Gate::define('timetable.generate', fn ($user = null) => true);

        $this->assertTrue(Authorize::can('generate'));
        $this->assertFalse(Authorize::can('edit'));
    }
}
