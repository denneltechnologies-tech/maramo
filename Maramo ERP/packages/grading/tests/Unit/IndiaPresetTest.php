<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\Assessment\Score;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Pass\GraceAllocator;
use Classivo\Grading\Pass\SubjectMarks;
use Classivo\Grading\Presets\India\IndiaPreset;
use Classivo\Grading\Presets\Presets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndiaPresetTest extends TestCase
{
    /** @return array<string, array{float, string, ?float, bool}> */
    public static function secondary(): array
    {
        return [
            '100' => [100, 'A1', 10.0, true],
            '91' => [91, 'A1', 10.0, true],
            '90.6 rounds to 91' => [90.6, 'A1', 10.0, true],
            '90.4' => [90.4, 'A2', 9.0, true],
            '81' => [81, 'A2', 9.0, true],
            '80' => [80, 'B1', 8.0, true],
            '61' => [61, 'B2', 7.0, true],
            '51' => [51, 'C1', 6.0, true],
            '41' => [41, 'C2', 5.0, true],
            '33' => [33, 'D', 4.0, true],
            '32.5 rounds to 33' => [32.5, 'D', 4.0, true],
            '32.4' => [32.4, 'E1', null, false],
            '21' => [21, 'E1', null, false],
            '20' => [20, 'E2', null, false],
            '0' => [0, 'E2', null, false],
        ];
    }

    #[DataProvider('secondary')]
    public function test_cbse_secondary_bands(float $pct, string $grade, ?float $gp, bool $passed): void
    {
        $r = IndiaPreset::cbseSecondary()->grade($pct);
        self::assertSame($grade, $r->label());
        self::assertSame($gp, $r->gradePoint());
        self::assertSame($passed, $r->passed);
    }

    public function test_cbse_primary_middle_bands(): void
    {
        $s = IndiaPreset::cbsePrimaryMiddle();
        self::assertSame(['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'D', 'E'], $s->scale()->labels());
        self::assertSame('E', $s->grade(32)->label());
        self::assertSame('Needs improvement', $s->grade(10)->remark());
        self::assertFalse($s->grade(32)->passed);
        self::assertSame('D', $s->grade(40)->label());
        self::assertSame('C2', $s->grade(40.5)->label()); // 40.5 -> 41
        self::assertNull($s->grade(95)->gradePoint());
    }

    public function test_icse_and_isc_pass_marks(): void
    {
        self::assertTrue(IndiaPreset::icse()->grade(33)->passed);
        self::assertFalse(IndiaPreset::icse()->grade(32.4)->passed);
        self::assertSame('Fail', IndiaPreset::icse()->grade(32.4)->label());
        self::assertFalse(IndiaPreset::isc()->grade(34)->passed);
        self::assertTrue(IndiaPreset::isc()->grade(35)->passed);
        self::assertTrue(IndiaPreset::icsePassRule()->evaluate(SubjectMarks::single(33, 100))->passed);
        self::assertFalse(IndiaPreset::iscPassRule()->evaluate(SubjectMarks::single(34, 100))->passed);
    }

    public function test_state_generic_divisions(): void
    {
        $s = IndiaPreset::stateGeneric();
        self::assertSame('Distinction', $s->grade(75)->label());
        self::assertSame('First Division', $s->grade(74.994)->label());
        self::assertSame('Third Division', $s->grade(33)->label());
        self::assertFalse($s->grade(32.99)->passed);
    }

    public function test_registry_default_and_keys(): void
    {
        $reg = Presets::make();
        self::assertSame('cbse.9-12', $reg->defaultKey());
        self::assertSame(['cbse.1-8', 'cbse.9-12', 'icse', 'isc', 'state.generic'], $reg->keys());
        self::assertSame($reg->keys(), IndiaPreset::registry()->keys());
    }

    public function test_neutral_preset(): void
    {
        $reg = Presets::make('neutral');
        self::assertSame(['generic.letter'], $reg->keys());
        self::assertSame('F', $reg->get()->grade(39)->label());
        self::assertTrue($reg->get()->grade(40)->passed);
    }

    public function test_unknown_preset(): void
    {
        $this->expectException(InvalidConfiguration::class);
        Presets::make('mars');
    }

    public function test_cbse_pass_rule_theory_practical(): void
    {
        $rule = IndiaPreset::cbsePassRule();
        self::assertFalse($rule->evaluate(new SubjectMarks(['theory' => [22, 70], 'practical' => [30, 30]]))->passed);
        self::assertTrue($rule->evaluate(new SubjectMarks(['theory' => [23, 70], 'practical' => [10, 30]]))->passed); // 32.86 -> 33
        self::assertTrue($rule->evaluate(SubjectMarks::single(33, 100))->passed);
    }

    public function test_grace_off_by_default(): void
    {
        $out = (new GraceAllocator(IndiaPreset::cbsePassRule(), IndiaPreset::gracePolicy()))
            ->evaluate(['maths' => SubjectMarks::single(31, 100)]);
        self::assertFalse($out->passed());
    }

    public function test_cgpa_preset(): void
    {
        $c = IndiaPreset::cbseCgpa();
        self::assertSame(9.5, $c->multiplier);
        self::assertSame(5, $c->subjectCount);
        self::assertSame(95.0, $c->indicativePercentage([10, 10, 10, 10, 10, 4]));
    }

    public function test_cgpa_from_scheme_grades(): void
    {
        $scheme = IndiaPreset::cbseSecondary();
        $gps = array_map(static fn ($p) => $scheme->grade($p)->gradePoint(), [95, 85, 75, 65, 55]);
        self::assertSame(8.0, IndiaPreset::cbseCgpa()->cgpa($gps));
        self::assertSame(76.0, IndiaPreset::cbseCgpa()->indicativePercentage($gps));
    }

    public function test_icse_best_of_five_and_isc_best_of_four(): void
    {
        $marks = ['english' => 70, 'a' => 90, 'b' => 80, 'c' => 60, 'd' => 50, 'e' => 95];
        self::assertSame(['english', 'e', 'a', 'b', 'c'], array_keys(IndiaPreset::icseBestOfFive()->aggregate($marks)->subjects));
        self::assertSame(['english', 'e', 'a', 'b'], array_keys(IndiaPreset::iscBestOfFour()->aggregate($marks)->subjects));
    }

    public function test_positional_board_grader(): void
    {
        $marks = [];
        for ($i = 0; $i < 8; $i++) {
            $marks["s{$i}"] = 90 - $i * 5;
        }
        $marks['f'] = 30;
        $out = IndiaPreset::positionalBoardGrader()->assign($marks);
        self::assertSame(['s0' => 'A1', 's1' => 'A2', 's2' => 'B1', 's3' => 'B2', 's4' => 'C1', 's5' => 'C2', 's6' => 'D1', 's7' => 'D2', 'f' => 'E'], $out);
        self::assertNull(IndiaPreset::positionalBoardGrader(500)->assign($marks));
    }

    public function test_cbse_term_plan(): void
    {
        $plan = IndiaPreset::cbseTermPlan();
        self::assertSame(100.0, $plan->maxTotal());
        $r = $plan->evaluate([
            'periodic_test' => [18, 20],
            'notebook' => [5, 5],
            'subject_enrichment' => [4, 5],
            'exam' => [60, 80],
        ]);
        self::assertEqualsWithDelta(78.0, $r->total, 1e-9);
    }

    public function test_cbse_secondary_plan_best_two_periodic_tests(): void
    {
        $plan = IndiaPreset::cbseSecondaryPlan();
        $r = $plan->evaluate([
            'periodic_test' => [[10, 40], [30, 40], [40, 40]], // best two: 1.0 + 0.75 -> 0.875 * 10
            'notebook' => [5, 5],
            'subject_enrichment' => [5, 5],
            'annual_exam' => [40, 80],
        ]);
        self::assertEqualsWithDelta(8.75, $r->breakdown['periodic_test'], 1e-9);
        self::assertEqualsWithDelta(58.75, $r->total, 1e-9);
        self::assertSame('C1', IndiaPreset::cbseSecondary()->grade($r->percentage())->label()); // 58.75 -> 59

        $avgAll = IndiaPreset::cbseSecondaryPlan(3)->evaluate([
            'periodic_test' => [[10, 40], [30, 40], [40, 40]],
            'notebook' => [5, 5], 'subject_enrichment' => [5, 5], 'annual_exam' => [40, 80],
        ]);
        self::assertEqualsWithDelta(6.6666667, $avgAll->breakdown['periodic_test'], 1e-6);
    }

    public function test_two_term_plan(): void
    {
        $r = IndiaPreset::twoTermPlan()->evaluate(['term1' => new Score(70, 100), 'term2' => new Score(90, 100)]);
        self::assertEqualsWithDelta(80.0, $r->percentage(), 1e-9);
        $r = IndiaPreset::twoTermPlan(30, 70)->evaluate(['term1' => new Score(70, 100), 'term2' => new Score(90, 100)]);
        self::assertEqualsWithDelta(84.0, $r->percentage(), 1e-9);
    }

    public function test_co_scholastic_scales(): void
    {
        self::assertSame('Outstanding', IndiaPreset::coScholasticThreePoint()->describe('A'));
        self::assertSame(['A', 'B', 'C'], IndiaPreset::coScholasticThreePoint()->labels());
        self::assertSame(['A', 'B', 'C', 'D', 'E'], IndiaPreset::coScholasticFivePoint()->labels());
        self::assertNull(IndiaPreset::coScholasticFivePoint()->describe('E'));
    }
}
