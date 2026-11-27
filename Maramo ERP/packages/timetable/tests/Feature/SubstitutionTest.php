<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\ClassTeacher;
use Classivo\Timetable\Events\SubstitutionAssigned;
use Classivo\Timetable\Events\SubstitutionCancelled;
use Classivo\Timetable\Filament\Pages\TeacherSubstitution;
use Classivo\Timetable\Filament\Resources\Substitutions\Pages\ListSubstitutions;
use Classivo\Timetable\Models\Substitution;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Services\SubstitutionService;
use Classivo\Timetable\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;

class SubstitutionTest extends TestCase
{
    private Carbon $monday;

    private array $t = [];

    private array $cs = [];

    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->foundation();
        Filament::setCurrentPanel('admin');
        $this->monday = Carbon::parse('next monday');

        $this->cs = $this->classWithSections('Grade 5', 5, ['A', 'B']);
        $this->subjects = ['mat' => $this->subject('Mathematics'), 'eng' => $this->subject('English')];
        foreach (['absent' => 'Absent Maths', 'maths' => 'Other Maths', 'busy' => 'Busy English', 'free' => 'Free Teacher', 'classT' => 'Class Five A'] as $k => $name) {
            $this->t[$k] = $this->teacher($name);
        }

        $a = Timetable::create(['class_section_id' => $this->cs[0]->id]);
        $b = Timetable::create(['class_section_id' => $this->cs[1]->id]);
        $period = fn (Timetable $tt, int $no, string $start, string $end, string $subj, string $teacher) => TimetablePeriod::create([
            'timetable_id' => $tt->id, 'day_of_week' => 1, 'period_no' => $no, 'start_time' => $start, 'end_time' => $end,
            'subject_id' => $this->subjects[$subj]->id, 'teacher_id' => $this->t[$teacher]->id,
        ]);
        $period($a, 1, '08:00', '08:45', 'mat', 'absent');
        $period($a, 2, '08:45', '09:30', 'mat', 'absent');
        $period($b, 1, '08:00', '08:45', 'eng', 'busy');
        $period($b, 2, '08:45', '09:30', 'eng', 'classT');
        $period($b, 3, '09:30', '10:15', 'mat', 'maths');
        // Tuesday period of the absent teacher is untouched by a Monday absence.
        TimetablePeriod::create(['timetable_id' => $a->id, 'day_of_week' => 2, 'period_no' => 1, 'start_time' => '08:00', 'end_time' => '08:45', 'teacher_id' => $this->t['absent']->id]);

        ClassSubject::create(['class_id' => $this->cs[0]->class_id, 'subject_id' => $this->subjects['mat']->id, 'teacher_id' => $this->t['maths']->id]);
        ClassTeacher::create(['class_section_id' => $this->cs[0]->id, 'teacher_id' => $this->t['free']->id, 'is_primary' => true]);
    }

    public function test_mark_absent_suggests_free_qualified_teachers_and_assigns(): void
    {
        Event::fake([SubstitutionAssigned::class]);
        $service = app(SubstitutionService::class);

        $rows = $service->markAbsent($this->t['absent']->id, $this->monday, 'Medical leave');
        $this->assertCount(2, $rows);
        $this->assertCount(2, $service->markAbsent($this->t['absent']->id, $this->monday), 'idempotent');
        $this->assertSame(2, Substitution::count());

        $first = $rows->firstWhere('period.period_no', 1) ?? $rows->first(fn ($s) => $s->period->period_no === 1);
        $candidates = $service->candidates($first);
        $ids = array_column($candidates, 'id');

        // Busy English teaches 5-B at 08:00; the absent teacher is excluded.
        $this->assertNotContains($this->t['busy']->id, $ids);
        $this->assertNotContains($this->t['absent']->id, $ids);
        // Best first: teaches maths, then the class teacher of 5-A.
        $this->assertSame($this->t['maths']->id, $candidates[0]['id']);
        $this->assertTrue($candidates[0]['subject_match']);
        $this->assertSame($this->t['free']->id, $candidates[1]['id']);
        $this->assertTrue($candidates[1]['knows_class']);

        $service->assign($first, $this->t['maths']->id, null, 'Covers algebra');
        $this->assertTrue($first->fresh()->isAssigned());
        Event::assertDispatched(SubstitutionAssigned::class, fn (SubstitutionAssigned $e) => $e->substituteTeacherId === $this->t['maths']->id
            && $e->classSectionId === $this->cs[0]->id && $e->periodNo === 1 && $e->date === $this->monday->toDateString());

        // The same teacher cannot cover a second overlapping slot... but period 2 does not overlap.
        $second = Substitution::all()->first(fn ($s) => $s->period->period_no === 2);
        $this->assertContains($this->t['maths']->id, array_column($service->candidates($second), 'id'));
        $this->assertSame(2, $service->loadOn($this->t['maths']->id, $this->monday), 'own period 3 + one cover');
    }

    public function test_assign_refuses_busy_absent_or_cancelled(): void
    {
        $service = app(SubstitutionService::class);
        $rows = $service->markAbsent($this->t['absent']->id, $this->monday);
        $first = $rows->first(fn ($s) => $s->period->period_no === 1);

        foreach ([$this->t['busy']->id, $this->t['absent']->id] as $teacher) {
            try {
                $service->assign($first, $teacher);
                $this->fail('should refuse');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        // A teacher who is absent the same day is not free either (classT is free at 08:00 otherwise).
        $this->assertContains($this->t['classT']->id, array_column($service->candidates($first), 'id'));
        $service->markAbsent($this->t['classT']->id, $this->monday);
        $this->assertNotContains($this->t['classT']->id, array_column($service->candidates($first), 'id'));

        Event::fake([SubstitutionCancelled::class]);
        $service->cancel($first);
        Event::assertDispatched(SubstitutionCancelled::class);
        $this->expectException(RuntimeException::class);
        $service->assign($first->fresh(), $this->t['maths']->id);
    }

    public function test_substitution_page_marks_absent_and_assigns(): void
    {
        Event::fake([SubstitutionAssigned::class]);
        $this->actingWith('timetable.substitute');

        $page = Livewire::test(TeacherSubstitution::class)
            ->assertOk()
            ->set('data.date', $this->monday->toDateString())
            ->set('data.teacher_id', $this->t['absent']->id)
            ->call('markAbsent')
            ->assertNotified('2 period(s) need cover.')
            ->assertSee('Other Maths');

        $first = Substitution::all()->first(fn ($s) => $s->period->period_no === 1);
        $page->call('assign', $first->id)->assertNotified('Choose a teacher');
        $page->set('choices.'.$first->id, $this->t['maths']->id)->call('assign', $first->id)->assertNotified('Substitute assigned.');
        $this->assertTrue($first->fresh()->isAssigned());

        $second = Substitution::all()->first(fn ($s) => $s->period->period_no === 2);
        // classT teaches 5-B at 08:45: refused even if posted directly.
        $page->set('choices.'.$second->id, $this->t['classT']->id)->call('assign', $second->id)->assertNotified('That teacher is not free in this period.');
        $this->assertFalse($second->fresh()->isAssigned());
    }

    public function test_permissions_and_register(): void
    {
        $this->actingWith('timetable.view');
        $this->assertFalse(TeacherSubstitution::canAccess());
        Livewire::test(TeacherSubstitution::class)->assertForbidden();

        app(SubstitutionService::class)->markAbsent($this->t['absent']->id, $this->monday, 'Training');
        Livewire::test(ListSubstitutions::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Substitution::all())
            ->assertSee('Training')
            ->assertTableActionHidden('cancel', Substitution::first());

        $this->actingWith();
        Livewire::test(ListSubstitutions::class)->assertForbidden();
    }
}
