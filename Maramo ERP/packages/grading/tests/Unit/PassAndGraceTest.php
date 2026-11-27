<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Pass\GraceAllocator;
use Classivo\Grading\Pass\GracePolicy;
use Classivo\Grading\Pass\PassRule;
use Classivo\Grading\Pass\SubjectMarks;
use Classivo\Grading\Rounding;
use PHPUnit\Framework\TestCase;

final class PassAndGraceTest extends TestCase
{
    private function cbseLike(): PassRule
    {
        return new PassRule(33, ['theory' => 33, 'practical' => 33], new Rounding(Rounding::NONE));
    }

    public function test_simple_pass_and_fail(): void
    {
        $rule = new PassRule(33, [], new Rounding(Rounding::NONE));
        self::assertTrue($rule->evaluate(SubjectMarks::single(33, 100))->passed);
        $o = $rule->evaluate(SubjectMarks::single(30, 100));
        self::assertFalse($o->passed);
        self::assertSame(['overall'], $o->failedParts);
        self::assertSame(3.0, $o->shortfall());
    }

    public function test_rounding_affects_pass(): void
    {
        // 26/80 = 32.5% -> half-up 33 -> pass
        $rule = new PassRule(33, [], new Rounding(Rounding::HALF_UP, 0));
        self::assertTrue($rule->evaluate(SubjectMarks::single(26, 80))->passed);
        $o = $rule->evaluate(SubjectMarks::single(25, 80));
        self::assertFalse($o->passed);
        self::assertSame(1.0, $o->shortfall());
    }

    public function test_part_minimum_fails_despite_overall_pass(): void
    {
        $o = $this->cbseLike()->evaluate(new SubjectMarks(['theory' => [20, 70], 'practical' => [30, 30]]));
        self::assertFalse($o->passed); // overall 50% but theory 28.6%
        self::assertSame(['theory'], $o->failedParts);
        self::assertSame(['theory' => 4.0], $o->shortfalls); // 33% of 70 = 23.1 -> 24 marks
    }

    public function test_missing_part_is_ignored(): void
    {
        self::assertTrue($this->cbseLike()->evaluate(new SubjectMarks(['theory' => [40, 100]]))->passed);
    }

    public function test_overall_shortfall_after_parts(): void
    {
        $o = $this->cbseLike()->evaluate(new SubjectMarks(['theory' => [24, 70], 'practical' => [5, 30]]));
        // practical needs 10 (33% of 30 = 9.9) -> +5; total becomes 34 >= 33 -> no extra overall need
        self::assertSame(['practical' => 5.0], $o->shortfalls);
        self::assertSame(['practical', 'overall'], $o->failedParts);
    }

    public function test_absent_counts_zero(): void
    {
        $o = (new PassRule(33, [], new Rounding(Rounding::NONE)))->evaluate(SubjectMarks::single(null, 100));
        self::assertFalse($o->passed);
        self::assertSame(33.0, $o->shortfall());
    }

    public function test_fractional_mark_step(): void
    {
        $rule = new PassRule(33, [], new Rounding(Rounding::NONE), 0.5);
        self::assertSame(0.5, $rule->evaluate(SubjectMarks::single(26, 80))->shortfall()); // needs 26.4 -> 26.5
    }

    public function test_no_grace_by_default(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)));
        $out = $alloc->evaluate(['maths' => SubjectMarks::single(32, 100)]);
        self::assertFalse($out->passed());
        self::assertSame(0.0, $out->totalGrace());
    }

    public function test_grace_within_limits_passes_closest_first(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(5, 6, 2));
        $out = $alloc->evaluate([
            'maths' => SubjectMarks::single(29, 100),   // short 4
            'science' => SubjectMarks::single(31, 100), // short 2
            'hindi' => SubjectMarks::single(60, 100),
        ]);
        self::assertTrue($out->passed());
        self::assertSame(['maths' => 4.0, 'science' => 2.0], $out->graceBySubject());
        self::assertSame(6.0, $out->totalGrace());
        self::assertSame(33.0, $out->subjects['maths']->marks->obtained());
    }

    public function test_grace_total_cap_serves_smallest_shortfall(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(5, 5, 3));
        $out = $alloc->evaluate([
            'maths' => SubjectMarks::single(29, 100),   // short 4
            'science' => SubjectMarks::single(31, 100), // short 2
        ]);
        self::assertSame(['science' => 2.0], $out->graceBySubject());
        self::assertSame(['maths'], $out->failedSubjects());
        self::assertFalse($out->passed());
    }

    public function test_grace_per_subject_cap(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(3, 10, 3));
        $out = $alloc->evaluate(['maths' => SubjectMarks::single(29, 100)]);
        self::assertSame([], $out->graceBySubject());
    }

    public function test_grace_percent_cap_is_tighter(): void
    {
        // 2% of 50 = 1 mark cap
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(5, 10, 3, false, 2));
        $out = $alloc->evaluate(['a' => SubjectMarks::single(15, 50), 'b' => SubjectMarks::single(16, 50)]);
        self::assertSame(['b' => 1.0], $out->graceBySubject()); // 33% of 50 = 16.5 -> 17
    }

    public function test_grace_subject_count_cap(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(5, 10, 1));
        $out = $alloc->evaluate(['a' => SubjectMarks::single(31, 100), 'b' => SubjectMarks::single(32, 100)]);
        self::assertSame(['b' => 1.0], $out->graceBySubject());
    }

    public function test_all_or_nothing_withholds_partial_grace(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)), new GracePolicy(5, 5, 3, true));
        $out = $alloc->evaluate(['maths' => SubjectMarks::single(29, 100), 'science' => SubjectMarks::single(31, 100)]);
        self::assertSame([], $out->graceBySubject());
        self::assertSame(['maths', 'science'], $out->failedSubjects());
    }

    public function test_grace_fills_part_and_overall(): void
    {
        $alloc = new GraceAllocator($this->cbseLike(), new GracePolicy(10, 10, 1));
        $out = $alloc->evaluate(['physics' => new SubjectMarks(['theory' => [22, 70], 'practical' => [9, 30]])]);
        // theory needs 24 (+2), practical needs 10 (+1); total 34 >= 33
        self::assertTrue($out->passed());
        self::assertSame(3.0, $out->totalGrace());
        self::assertSame(24.0, $out->subjects['physics']->marks->parts['theory']->obtained);
        self::assertSame(10.0, $out->subjects['physics']->marks->parts['practical']->obtained);
    }

    public function test_overall_grace_spreads_across_parts(): void
    {
        $rule = new PassRule(50, [], new Rounding(Rounding::NONE));
        $alloc = new GraceAllocator($rule, new GracePolicy(10, 10, 1));
        $out = $alloc->evaluate(['x' => new SubjectMarks(['a' => [9, 10], 'b' => [37, 90]])]); // 46, needs 4
        self::assertTrue($out->passed());
        self::assertSame(10.0, $out->subjects['x']->marks->parts['a']->obtained);
        self::assertSame(40.0, $out->subjects['x']->marks->parts['b']->obtained);
    }

    public function test_per_subject_rule_override(): void
    {
        $alloc = new GraceAllocator(new PassRule(33, [], new Rounding(Rounding::NONE)));
        $out = $alloc->evaluate(
            ['english' => SubjectMarks::single(34, 100), 'maths' => SubjectMarks::single(34, 100)],
            ['english' => new PassRule(35, [], new Rounding(Rounding::NONE))],
        );
        self::assertSame(['english'], $out->failedSubjects());
    }

    public function test_from_array_configs(): void
    {
        $rule = PassRule::fromArray(['overall' => 35, 'parts' => ['theory' => 30], 'mark_step' => 0.5]);
        self::assertSame(['theory' => 30.0], $rule->partMinimums);
        self::assertSame(0.5, $rule->markStep);
        $g = GracePolicy::fromArray(['max_per_subject' => 5, 'max_total' => 10, 'max_subjects' => 2, 'all_or_nothing' => true]);
        self::assertTrue($g->allOrNothing);
        self::assertSame(5.0, $g->capFor(100));
        self::assertSame(0.0, GracePolicy::fromArray(null)->maxTotal);
    }

    public function test_invalid_rule(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new PassRule(120);
    }

    public function test_invalid_grace(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new GracePolicy(-1);
    }

    public function test_with_added_caps_at_max_and_unknown_part(): void
    {
        $m = SubjectMarks::single(95, 100)->withAdded('total', 10);
        self::assertSame(100.0, $m->obtained());
        $this->expectException(InvalidConfiguration::class);
        $m->withAdded('practical', 1);
    }
}
