<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\Assessment\Component;
use Classivo\Grading\Assessment\Reduction;
use Classivo\Grading\Assessment\Score;
use Classivo\Grading\Assessment\WeightagePlan;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;
use PHPUnit\Framework\TestCase;

final class WeightageTest extends TestCase
{
    /** @return list<Score> */
    private function threeTests(): array
    {
        return [new Score(20, 40), new Score(36, 40), new Score(30, 40)]; // 0.5, 0.9, 0.75
    }

    public function test_reductions(): void
    {
        $s = $this->threeTests();
        self::assertEqualsWithDelta(0.7166666, (new Reduction(Reduction::AVERAGE))->reduce($s), 1e-6);
        self::assertEqualsWithDelta(0.825, Reduction::bestOf(2)->reduce($s), 1e-9);
        self::assertEqualsWithDelta(0.9, (new Reduction(Reduction::BEST))->reduce($s), 1e-9);
        self::assertEqualsWithDelta(0.75, (new Reduction(Reduction::LATEST))->reduce($s), 1e-9);
        self::assertEqualsWithDelta(86 / 120, (new Reduction(Reduction::SUM))->reduce($s), 1e-9);
        self::assertEqualsWithDelta(66 / 80, (new Reduction(Reduction::BEST_N_SUM, 2))->reduce($s), 1e-9);
        self::assertSame(0.0, (new Reduction)->reduce([]));
    }

    public function test_best_n_larger_than_attempts_uses_all(): void
    {
        self::assertEqualsWithDelta(0.7, Reduction::bestOf(3)->reduce([new Score(7, 10), new Score(14, 20)]), 1e-9);
    }

    public function test_best_n_sum_with_unequal_maxima(): void
    {
        // best ratios: 18/20 (0.9), 5/10 (0.5) -> (18+5)/(20+10)
        $r = (new Reduction(Reduction::BEST_N_SUM, 2))->reduce([new Score(5, 10), new Score(18, 20), new Score(1, 10)]);
        self::assertEqualsWithDelta(23 / 30, $r, 1e-9);
    }

    public function test_reduction_validation(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Reduction(Reduction::BEST_N_AVERAGE);
    }

    public function test_unknown_reduction(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Reduction('median');
    }

    public function test_plan_totals_and_breakdown(): void
    {
        $plan = new WeightagePlan([
            new Component('pt', 10, Reduction::bestOf(2)),
            new Component('nb', 5),
            new Component('exam', 80),
        ]);
        $r = $plan->evaluate([
            'pt' => $this->threeTests(),
            'nb' => [4, 5],
            'exam' => new Score(60, 80),
        ]);
        self::assertSame(95.0, $r->max);
        self::assertEqualsWithDelta(8.25, $r->breakdown['pt'], 1e-9);
        self::assertEqualsWithDelta(4.0, $r->breakdown['nb'], 1e-9);
        self::assertEqualsWithDelta(72.25, $r->total, 1e-9);
        self::assertEqualsWithDelta(72.25 / 95 * 100, $r->percentage(), 1e-9);
        self::assertEqualsWithDelta(72.25, $r->toScore()->obtained, 1e-9);
    }

    public function test_absent_as_zero_vs_excluded(): void
    {
        $attempts = [new Score(8, 10), Score::absent(10)];
        self::assertEqualsWithDelta(4.0, (new Component('pt', 10, new Reduction, true))->contribution($attempts), 1e-9);
        self::assertEqualsWithDelta(8.0, (new Component('pt', 10, new Reduction, false))->contribution($attempts), 1e-9);
        self::assertSame(0.0, (new Component('pt', 10, new Reduction, false))->contribution([Score::absent(10)]));
    }

    public function test_component_and_total_rounding(): void
    {
        $plan = new WeightagePlan(
            [new Component('a', 10), new Component('b', 10)],
            new Rounding(Rounding::HALF_UP, 0),
            new Rounding(Rounding::DOWN, 0),
        );
        $r = $plan->evaluate(['a' => [4.5, 10], 'b' => [4.4, 10]]);
        self::assertSame(['a' => 5.0, 'b' => 4.0], $r->breakdown);
        self::assertSame(9.0, $r->total);
    }

    public function test_term_combination(): void
    {
        $plan = new WeightagePlan([new Component('term1', 40), new Component('term2', 60)]);
        $r = $plan->evaluate(['term1' => [70, 100], 'term2' => [80, 100]]);
        self::assertEqualsWithDelta(76.0, $r->percentage(), 1e-9);
    }

    public function test_from_array(): void
    {
        $plan = WeightagePlan::fromArray([
            'components' => [
                ['key' => 'pt', 'weight' => 20, 'reduction' => 'best_n_average', 'n' => 1],
                ['key' => 'exam', 'weight' => 80, 'absent_as_zero' => false],
            ],
            'total_rounding' => ['mode' => 'half_up', 'precision' => 0],
        ]);
        $r = $plan->evaluate(['pt' => [[10, 20], [15, 20]], 'exam' => [[null, 80], [40, 80]]]);
        self::assertSame(55.0, $r->total);
        self::assertSame(100.0, $plan->maxTotal());
    }

    public function test_missing_component_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        (new WeightagePlan([new Component('a', 10)]))->evaluate([]);
    }

    public function test_unknown_component_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        (new WeightagePlan([new Component('a', 10)]))->evaluate(['a' => [1, 10], 'b' => [1, 10]]);
    }

    public function test_duplicate_component_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new WeightagePlan([new Component('a', 10), new Component('a', 5)]);
    }

    public function test_score_validation(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Score(11, 10);
    }

    public function test_score_from_needs_max(): void
    {
        $this->expectException(InvalidConfiguration::class);
        Score::from(5);
    }

    public function test_score_from_shapes(): void
    {
        self::assertSame(50.0, Score::from(['obtained' => 5, 'max' => 10])->percent());
        self::assertSame(50.0, Score::from(5, 10)->percent());
        self::assertTrue(Score::from([null, 10])->isAbsent());
    }
}
