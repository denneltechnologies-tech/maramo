<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Core\Models\ClassSubject;
use Classivo\Timetable\Actions\GenerateTimetables;
use Classivo\Timetable\Generator\Result;
use Classivo\Timetable\Generator\Room;
use Classivo\Timetable\Models\Room as RoomModel;
use Classivo\Timetable\Models\SubjectRequirement;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Tests\TestCase;

class GenerateTimetablesTest extends TestCase
{
    /** Bell schedule: 5 periods + a break after period 3. */
    private const BELLS = [
        ['start' => '08:00', 'end' => '08:45'],
        ['start' => '08:45', 'end' => '09:30'],
        ['start' => '09:30', 'end' => '10:15'],
        ['start' => '10:15', 'end' => '10:35', 'break' => true],
        ['start' => '10:35', 'end' => '11:20'],
        ['start' => '11:20', 'end' => '12:05'],
    ];

    private function action(): GenerateTimetables
    {
        return app(GenerateTimetables::class);
    }

    /** Two classes × two sections, 4 subjects, teachers shared across sections. */
    private function school(): array
    {
        $this->foundation();
        $grade6 = $this->classWithSections('Grade 6', 6, ['A', 'B']);
        $grade7 = $this->classWithSections('Grade 7', 7, ['A', 'B']);
        $subjects = [
            'mat' => $this->subject('Mathematics'),
            'eng' => $this->subject('English'),
            'sci' => $this->subject('Science'),
            'art' => $this->subject('Art'),
        ];
        $teachers = [
            'mat' => $this->teacher('Maths Teacher'),
            'eng' => $this->teacher('English Teacher'),
            'sci' => $this->teacher('Science Teacher'),
            'art' => $this->teacher('Art Teacher'),
        ];
        foreach ([$grade6[0], $grade7[0]] as $cs) {
            foreach ($subjects as $key => $subject) {
                ClassSubject::create([
                    'class_id' => $cs->class_id,
                    'section_id' => null,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teachers[$key]->id,
                ]);
            }
        }

        return [array_merge($grade6, $grade7), $subjects, $teachers];
    }

    public function test_generates_and_saves_clash_free_timetables(): void
    {
        [$sections, $subjects, $teachers] = $this->school();

        $out = $this->action()->handle(
            weeklyPeriods: [$subjects['mat']->id => 6, $subjects['eng']->id => 5, $subjects['sci']->id => 5, $subjects['art']->id => 2],
            days: [1, 2, 3, 4, 5, 6],
            periods: self::BELLS,
            maxConsecutive: 4,
        );

        $this->assertSame(Result::SOLVED, $out->result->status, $out->result->report());
        $this->assertTrue($out->saved());
        $this->assertCount(4, $out->timetableIds);

        foreach ($sections as $cs) {
            $tt = Timetable::where('class_section_id', $cs->id)->firstOrFail();
            $this->assertSame(18, $tt->periods()->where('is_break', false)->count());
            $this->assertSame(6, $tt->periods()->where('is_break', true)->count());
        }

        // Independent clash check over the saved rows.
        $rows = TimetablePeriod::where('is_break', false)->get();
        $this->assertSame(72, $rows->count());
        $this->assertSame($rows->count(), $rows->unique(fn ($p) => $p->teacher_id.'|'.$p->day_of_week.'|'.$p->period_no)->count());
        $this->assertSame($rows->count(), $rows->unique(fn ($p) => $p->timetable_id.'|'.$p->day_of_week.'|'.$p->period_no)->count());
        $this->assertSame(0, $rows->where('period_no', 4)->count());
        $this->assertSame('10:35', TimetablePeriod::formatTime($rows->firstWhere('period_no', 5)?->start_time));
        // Maths teacher teaches all 4 sections.
        $this->assertSame(24, $rows->where('teacher_id', $teachers['mat']->id)->count());
    }

    public function test_section_specific_allotment_and_class_override(): void
    {
        [$sections, $subjects, $teachers] = $this->school();
        [$g6a, $g6b] = $sections;
        $other = $this->teacher('Other Maths Teacher');
        ClassSubject::create([
            'class_id' => $g6b->class_id,
            'section_id' => $g6b->section_id,
            'subject_id' => $subjects['mat']->id,
            'teacher_id' => $other->id,
        ]);

        $out = $this->action()->handle(
            classSectionIds: [$g6a->id, $g6b->id],
            weeklyPeriods: [$subjects['mat']->id => 4, $subjects['eng']->id => 4, $subjects['sci']->id => 4, $subjects['art']->id => 2],
            classWeeklyPeriods: [$g6a->id => [$subjects['art']->id => 0]],
            days: [1, 2, 3, 4, 5],
            periods: self::BELLS,
        );

        $this->assertTrue($out->saved(), $out->result->report());
        $this->assertSame(2, Timetable::count());
        $b = Timetable::where('class_section_id', $g6b->id)->first();
        $this->assertSame(4, $b->periods()->where('teacher_id', $other->id)->count());
        $this->assertSame(0, $b->periods()->where('teacher_id', $teachers['mat']->id)->count());
        $a = Timetable::where('class_section_id', $g6a->id)->first();
        $this->assertSame(0, $a->periods()->where('subject_id', $subjects['art']->id)->count());
    }

    public function test_respects_timetables_that_are_kept(): void
    {
        [$sections, $subjects, $teachers] = $this->school();
        [$g6a, , $g7a] = $sections;

        // Grade 7-A keeps a hand-made timetable: the maths teacher is busy
        // Monday periods 1–2 there.
        $kept = Timetable::create(['class_section_id' => $g7a->id]);
        foreach ([0, 1] as $i) {
            TimetablePeriod::create([
                'timetable_id' => $kept->id, 'day_of_week' => 1, 'period_no' => $i + 1,
                'start_time' => self::BELLS[$i]['start'], 'end_time' => self::BELLS[$i]['end'],
                'subject_id' => $subjects['mat']->id, 'teacher_id' => $teachers['mat']->id,
            ]);
        }

        $out = $this->action()->handle(
            classSectionIds: [$g6a->id],
            weeklyPeriods: [$subjects['mat']->id => 5, $subjects['eng']->id => 5, $subjects['sci']->id => 5, $subjects['art']->id => 5],
            days: [1, 2, 3, 4],
            periods: self::BELLS,
        );

        // Grade 6-A needs all 20 slots of the 4-day week, so its Monday 1–2
        // must avoid the maths teacher — and the model guard would reject a clash.
        $this->assertTrue($out->saved(), $out->result->report());
        $this->assertSame(2, $kept->periods()->count());
        $monday = Timetable::where('class_section_id', $g6a->id)->first()->periods()
            ->where('day_of_week', 1)->whereIn('period_no', [1, 2])->pluck('teacher_id')->all();
        $this->assertNotContains($teachers['mat']->id, $monday);
    }

    public function test_teacher_unavailability_and_rooms_are_passed_through(): void
    {
        [$sections, $subjects, $teachers] = $this->school();

        $out = $this->action()->handle(
            weeklyPeriods: [$subjects['mat']->id => 4, $subjects['eng']->id => 4, $subjects['sci']->id => 3, $subjects['art']->id => 2],
            teacherUnavailable: [$teachers['art']->id => [[1, 1], [1, 2], [1, 3], [1, 5], [1, 6]]],
            subjectRoomTypes: [$subjects['sci']->id => 'lab'],
            rooms: [new Room('LAB-1', 'lab'), new Room('LAB-2', 'lab')],
            days: [1, 2, 3, 4, 5],
            periods: self::BELLS,
        );

        $this->assertTrue($out->saved(), $out->result->report());
        $this->assertSame(0, TimetablePeriod::where('teacher_id', $teachers['art']->id)->where('day_of_week', 1)->count());
        $science = TimetablePeriod::where('subject_id', $subjects['sci']->id)->get();
        $this->assertSame(12, $science->count());
        $this->assertTrue($science->every(fn ($p) => in_array($p->room, ['LAB-1', 'LAB-2'], true)));
        $this->assertSame($science->count(), $science->unique(fn ($p) => $p->room.'|'.$p->day_of_week.'|'.$p->period_no)->count());
    }

    public function test_reads_requirements_and_rooms_from_the_database_and_arguments_override(): void
    {
        [$sections, $subjects] = $this->school();
        [$g6a, $g6b] = $sections;
        $classId = $g6a->class_id;
        foreach (['mat' => 6, 'eng' => 5, 'sci' => 4, 'art' => 1] as $key => $n) {
            SubjectRequirement::create(['class_id' => $classId, 'subject_id' => $subjects[$key]->id, 'weekly_periods' => $n, 'room_type' => $key === 'sci' ? 'lab' : null]);
        }
        // Section B overrides English.
        SubjectRequirement::create(['class_id' => $classId, 'section_id' => $g6b->section_id, 'subject_id' => $subjects['eng']->id, 'weekly_periods' => 3]);
        RoomModel::create(['name' => 'LAB-1', 'type' => 'lab']);
        RoomModel::create(['name' => 'LAB-OFF', 'type' => 'lab', 'status' => false]);

        $out = $this->action()->handle(
            classSectionIds: [$g6a->id, $g6b->id],
            weeklyPeriods: [$subjects['art']->id => 2],   // argument beats the stored 1
            days: [1, 2, 3, 4, 5],
            periods: self::BELLS,
        );

        $this->assertTrue($out->saved(), $out->result->report());
        $a = Timetable::where('class_section_id', $g6a->id)->first();
        $b = Timetable::where('class_section_id', $g6b->id)->first();
        $this->assertSame(6, $a->periods()->where('subject_id', $subjects['mat']->id)->count());
        $this->assertSame(5, $a->periods()->where('subject_id', $subjects['eng']->id)->count());
        $this->assertSame(3, $b->periods()->where('subject_id', $subjects['eng']->id)->count());
        $this->assertSame(2, $a->periods()->where('subject_id', $subjects['art']->id)->count());
        $science = TimetablePeriod::where('subject_id', $subjects['sci']->id)->pluck('room')->unique()->values()->all();
        $this->assertSame(['LAB-1'], $science, 'only the active lab is booked');
    }

    public function test_unsatisfiable_saves_nothing(): void
    {
        [, $subjects] = $this->school();

        $out = $this->action()->handle(
            weeklyPeriods: [$subjects['mat']->id => 10, $subjects['eng']->id => 5, $subjects['sci']->id => 5, $subjects['art']->id => 5],
            days: [1, 2, 3, 4],
            periods: self::BELLS,
        );

        $this->assertSame(Result::UNSATISFIABLE, $out->result->status);
        $this->assertFalse($out->saved());
        $this->assertStringContainsString('needs 25 periods a week but only 20 slots exist', $out->result->report());
        $this->assertSame(0, Timetable::count());
    }

    public function test_dry_run_and_config_defaults(): void
    {
        $this->school();

        // Default bell schedule (8 periods + break), India preset → Mon–Sat, 5 a week per subject.
        $out = $this->action()->handle(save: false);

        $this->assertSame(Result::SOLVED, $out->result->status, $out->result->report());
        $this->assertCount(80, $out->result->placements);
        $this->assertSame([1, 2, 3, 4, 5, 6], collect($out->result->placements)->pluck('day')->unique()->sort()->values()->all());
        $this->assertSame(0, collect($out->result->placements)->where('period', 5)->count()); // the break
        $this->assertSame(0, Timetable::count());
    }
}
