<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\Aggregate\BestOfAggregator;
use Classivo\Grading\Aggregate\CgpaCalculator;
use Classivo\Grading\Aggregate\PositionalGrader;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;
use PHPUnit\Framework\TestCase;

final class AggregateTest extends TestCase
{
    public function test_cgpa_and_indicative_percentage(): void
    {
        $c = new CgpaCalculator(9.5, 5);
        // best five of six: 10, 10, 9, 9, 8 -> 9.2 ; the 6 is dropped
        $gps = ['eng' => 10, 'hin' => 9, 'mat' => 10, 'sci' => 8, 'sst' => 9, 'it' => 6];
        self::assertSame(9.2, $c->cgpa($gps));
        self::assertSame(87.4, $c->indicativePercentage($gps));
        self::assertSame(95.0, $c->subjectPercentage(10));
    }

    public function test_cgpa_null_point_makes_undefined_unless_skipped(): void
    {
        self::assertNull((new CgpaCalculator)->cgpa([10, null]));
        self::assertNull((new CgpaCalculator)->indicativePercentage([10, null]));
        self::assertSame(10.0, (new CgpaCalculator(9.5, null, new Rounding, new Rounding, true))->cgpa([10, null]));
        self::assertNull((new CgpaCalculator)->cgpa([]));
    }

    public function test_cgpa_without_multiplier(): void
    {
        $c = new CgpaCalculator(null);
        self::assertSame(8.5, $c->cgpa([8, 9]));
        self::assertNull($c->indicativePercentage([8, 9]));
        self::assertNull($c->subjectPercentage(8));
    }

    public function test_cgpa_rounding_one_decimal(): void
    {
        self::assertSame(8.7, (new CgpaCalculator)->cgpa([9, 9, 8])); // 8.666.. -> 8.7
    }

    public function test_cgpa_invalid(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new CgpaCalculator(9.5, 0);
    }

    public function test_best_of_five_with_compulsory_english(): void
    {
        $agg = new BestOfAggregator(5, ['english']);
        $r = $agg->aggregate(['english' => 60, 'maths' => 95, 'physics' => 90, 'chem' => 85, 'bio' => 70, 'hindi' => 80]);
        self::assertSame(['english', 'maths', 'physics', 'chem', 'hindi'], array_keys($r->subjects));
        self::assertSame(82.0, $r->average);
        self::assertTrue($r->complete);
    }

    public function test_best_of_ties_keep_input_order(): void
    {
        $r = (new BestOfAggregator(2))->aggregate(['a' => 80, 'b' => 90, 'c' => 80]);
        self::assertSame(['b', 'a'], array_keys($r->subjects));
    }

    public function test_best_of_incomplete(): void
    {
        $r = (new BestOfAggregator(5, ['english']))->aggregate(['english' => 50, 'maths' => 70]);
        self::assertFalse($r->complete);
        self::assertSame(60.0, $r->average);
    }

    public function test_best_of_missing_compulsory(): void
    {
        $this->expectException(InvalidConfiguration::class);
        (new BestOfAggregator(5, ['english']))->aggregate(['maths' => 70]);
    }

    public function test_best_of_too_many_compulsory(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new BestOfAggregator(1, ['a', 'b']);
    }

    public function test_positional_eighths(): void
    {
        $g = new PositionalGrader(['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'D1', 'D2'], 33);
        $marks = [];
        for ($i = 1; $i <= 16; $i++) {
            $marks["s{$i}"] = 100 - $i * 4; // 96 .. 36, all pass
        }
        $marks['fail'] = 20;
        $out = $g->assign($marks);
        self::assertNotNull($out);
        self::assertSame('A1', $out['s1']);
        self::assertSame('A1', $out['s2']);
        self::assertSame('A2', $out['s3']);
        self::assertSame('D2', $out['s16']);
        self::assertSame('E', $out['fail']);
        self::assertSame(array_keys($marks), array_keys($out));
        $counts = array_count_values($out);
        self::assertSame(2, $counts['C1']);
    }

    public function test_positional_ties_share_better_grade(): void
    {
        $g = new PositionalGrader(['A', 'B'], 33);
        $out = $g->assign(['x' => 90, 'y' => 80, 'z' => 80, 'w' => 50]);
        // ranks 0,1 -> A ; z ties y so A too ; w rank 3 -> B
        self::assertSame(['x' => 'A', 'y' => 'A', 'z' => 'A', 'w' => 'B'], $out);
    }

    public function test_positional_small_cohort_returns_null(): void
    {
        $g = new PositionalGrader(['A', 'B'], 33, 'E', 3);
        self::assertNull($g->assign(['x' => 90, 'y' => 80, 'z' => 10]));
    }

    public function test_positional_rounding_pass(): void
    {
        $g = new PositionalGrader(['A'], 33);
        self::assertSame(['x' => 'A', 'y' => 'E'], $g->assign(['x' => 32.5, 'y' => 32.4]));
    }

    public function test_positional_fail_label_must_differ(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new PositionalGrader(['A', 'E'], 33, 'E');
    }
}
