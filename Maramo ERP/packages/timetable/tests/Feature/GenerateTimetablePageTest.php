<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Core\Models\ClassSubject;
use Classivo\Timetable\Filament\Pages\GenerateTimetable;
use Classivo\Timetable\Models\Room;
use Classivo\Timetable\Models\SubjectRequirement;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;

class GenerateTimetablePageTest extends TestCase
{
    private array $sections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foundation();
        Filament::setCurrentPanel('admin');
        $this->actingWith('timetable.generate');

        $this->sections = $this->classWithSections('Grade 5', 5, ['A', 'B']);
        $classId = $this->sections[0]->class_id;
        foreach (['Mathematics' => 6, 'English' => 6, 'Science' => 5, 'Hindi' => 5] as $name => $weekly) {
            $subject = $this->subject($name);
            ClassSubject::create(['class_id' => $classId, 'subject_id' => $subject->id, 'teacher_id' => $this->teacher("{$name} Teacher")->id]);
            SubjectRequirement::create(['class_id' => $classId, 'subject_id' => $subject->id, 'weekly_periods' => $weekly, 'room_type' => $name === 'Science' ? 'lab' : null]);
        }
        Room::create(['name' => 'LAB-1', 'type' => 'lab']);
    }

    public function test_defaults_preview_then_save(): void
    {
        $page = Livewire::test(GenerateTimetable::class)->assertOk();
        $this->assertCount(2, $page->get('data.class_section_ids'));
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_values($page->get('data.days')));

        $page->call('generatePreview')
            ->assertHasNoErrors()
            ->assertSee('Preview — clash-free timetable found')
            ->assertSee('Grade 5 - A')
            ->assertSee('Mathematics · Mathematics Teacher')
            ->assertSee('Science · Science Teacher · LAB-1')
            ->assertSee('Save timetables');

        $preview = $page->get('preview');
        $this->assertSame('solved', $preview['status']);
        $this->assertCount(2, $preview['classes']);
        $this->assertSame(0, Timetable::count(), 'preview is a dry run');

        $page->call('saveTimetables')->assertNotified('2 timetable(s) saved.');
        $this->assertNull($page->get('preview'));
        $this->assertSame(2, Timetable::count());
        $this->assertSame(44, TimetablePeriod::where('is_break', false)->count());
        $this->assertSame(10, TimetablePeriod::where('subject_id', SubjectRequirement::where('room_type', 'lab')->value('subject_id'))->where('room', 'LAB-1')->count());
    }

    public function test_saved_timetable_matches_the_preview(): void
    {
        $page = Livewire::test(GenerateTimetable::class)
            ->set('data.class_section_ids', [(string) $this->sections[0]->id])
            ->set('data.seed', 42)
            ->call('generatePreview');
        $grid = $page->get('preview')['classes'][0]['rows'];

        $page->call('saveTimetables');

        $periods = Timetable::firstOrFail()->periods()->with('subject')->get();
        foreach ($grid as $row) {
            foreach ($row['cells'] as $day => $cell) {
                if ($row['break'] || $cell === '') {
                    continue;
                }
                $saved = $periods->first(fn ($p) => $p->day_of_week === $day && $p->period_no === $row['no']);
                $this->assertNotNull($saved, "day {$day} period {$row['no']}");
                $this->assertStringStartsWith($saved->subject->name, $cell);
            }
        }
    }

    public function test_unsatisfiable_preview_lists_reasons_and_hides_save(): void
    {
        SubjectRequirement::query()->update(['weekly_periods' => 15]);

        Livewire::test(GenerateTimetable::class)
            ->set('data.days', ['1', '2', '3'])
            ->call('generatePreview')
            ->assertSee('No clash-free timetable is possible')
            ->assertSee('needs 60 periods a week but only 24 slots exist')
            ->assertDontSee('Save timetables');

        $this->assertSame(0, Timetable::count());
    }

    public function test_save_with_unsolvable_settings_saves_nothing_and_notifies(): void
    {
        SubjectRequirement::query()->update(['weekly_periods' => 15]);

        Livewire::test(GenerateTimetable::class)
            ->call('saveTimetables')
            ->assertNotified('Nothing saved — the timetable could not be generated.');

        $this->assertSame(0, Timetable::count());
    }

    public function test_settings_are_validated(): void
    {
        Livewire::test(GenerateTimetable::class)
            ->set('data.class_section_ids', [])
            ->set('data.time_limit_seconds', 0)
            ->call('generatePreview')
            ->assertHasErrors(['data.class_section_ids', 'data.time_limit_seconds']);
    }
}
