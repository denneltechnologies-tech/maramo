<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Core\Models\ClassSubject;
use Classivo\Timetable\Filament\Pages\TimetableRequirements;
use Classivo\Timetable\Models\Room;
use Classivo\Timetable\Models\SubjectRequirement;
use Classivo\Timetable\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;

class TimetableRequirementsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->foundation();
        Filament::setCurrentPanel('admin');
        $this->actingWith('timetable.edit');
    }

    public function test_class_and_section_requirements_and_rooms_are_saved(): void
    {
        [$a, $b] = $this->classWithSections('Grade 8', 8, ['A', 'B']);
        $maths = $this->subject('Mathematics');
        $science = $this->subject('Science');
        foreach ([$maths, $science] as $subject) {
            ClassSubject::create(['class_id' => $a->class_id, 'section_id' => null, 'subject_id' => $subject->id]);
        }

        // Class-wide rows: pre-filled from config default (5).
        $page = Livewire::test(TimetableRequirements::class)
            ->assertOk()
            ->set('data.class_id', $a->class_id);
        $rows = array_values($page->get('data.subjects'));
        $this->assertCount(2, $rows);
        $this->assertSame(5, $rows[0]['weekly_periods']);

        $page->set('data.subjects', [
            ['subject_id' => $maths->id, 'subject_name' => 'Mathematics', 'weekly_periods' => 7, 'room_type' => ''],
            ['subject_id' => $science->id, 'subject_name' => 'Science', 'weekly_periods' => 6, 'room_type' => 'lab'],
        ])
            ->set('data.rooms', [['id' => null, 'name' => 'LAB-1', 'type' => 'lab', 'status' => true]])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Requirements saved.');

        $this->assertDatabaseHas('timetable_subject_requirements', ['class_id' => $a->class_id, 'section_id' => null, 'subject_id' => $maths->id, 'weekly_periods' => 7, 'room_type' => null]);
        $this->assertDatabaseHas('timetable_subject_requirements', ['subject_id' => $science->id, 'room_type' => 'lab', 'weekly_periods' => 6]);
        $this->assertSame(['LAB-1'], Room::pluck('name')->all());

        // Section B: pre-filled from the class-wide row, saved as its own override.
        $page = Livewire::test(TimetableRequirements::class)
            ->set('data.class_id', $b->class_id)
            ->set('data.section_id', $b->section_id);
        $rows = array_values($page->get('data.subjects'));
        $this->assertSame(7, $rows[0]['weekly_periods']);
        $this->assertCount(1, $page->get('data.rooms'));

        $rows[0]['weekly_periods'] = 8;
        $page->set('data.subjects', $rows)->call('save')->assertHasNoErrors();

        $this->assertSame(4, SubjectRequirement::count()); // 2 class-wide + 2 section-B rows
        $this->assertSame(8, SubjectRequirement::where('section_id', $b->section_id)->value('weekly_periods'));
        $this->assertSame(7, SubjectRequirement::whereNull('section_id')->where('subject_id', $maths->id)->value('weekly_periods'));
    }

    public function test_rooms_can_be_renamed_and_removed(): void
    {
        $lab = Room::create(['name' => 'LAB-1', 'type' => 'lab']);
        Room::create(['name' => 'OLD', 'type' => 'lab']);

        Livewire::test(TimetableRequirements::class)
            ->set('data.rooms', [['id' => $lab->id, 'name' => 'Physics Lab', 'type' => 'lab', 'status' => false]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Physics Lab'], Room::pluck('name')->all());
        $this->assertFalse(Room::first()->status);
    }

    public function test_weekly_periods_are_validated(): void
    {
        [$a] = $this->classWithSections('Grade 8', 8, ['A']);
        $maths = $this->subject('Mathematics');
        ClassSubject::create(['class_id' => $a->class_id, 'subject_id' => $maths->id]);

        Livewire::test(TimetableRequirements::class)
            ->set('data.class_id', $a->class_id)
            ->set('data.subjects', [['subject_id' => $maths->id, 'subject_name' => 'Mathematics', 'weekly_periods' => -1, 'room_type' => null]])
            ->call('save')
            ->assertHasErrors();

        $this->assertSame(0, SubjectRequirement::count());
    }
}
