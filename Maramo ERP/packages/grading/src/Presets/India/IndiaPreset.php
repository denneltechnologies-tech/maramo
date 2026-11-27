<?php

declare(strict_types=1);

namespace Classivo\Grading\Presets\India;

use Classivo\Grading\Aggregate\BestOfAggregator;
use Classivo\Grading\Aggregate\CgpaCalculator;
use Classivo\Grading\Aggregate\PositionalGrader;
use Classivo\Grading\Assessment\Component;
use Classivo\Grading\Assessment\Reduction;
use Classivo\Grading\Assessment\WeightagePlan;
use Classivo\Grading\CoScholastic\CoScholasticScale;
use Classivo\Grading\Pass\GracePolicy;
use Classivo\Grading\Pass\PassRule;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\Band;
use Classivo\Grading\Scale\GradeScale;
use Classivo\Grading\Schemes\PercentageScheme;
use Classivo\Grading\Schemes\SchemeRegistry;

/**
 * Indian board presets. Every number here is cited in SOURCES.md (look up the [S#]
 * tag); anything uncertain is listed in VERIFICATION.md and is a plain constructor argument
 * or config value so a school can change it without code.
 */
final class IndiaPreset
{
    public const CBSE_1_8 = 'cbse.1-8';

    public const CBSE_9_12 = 'cbse.9-12';

    public const ICSE = 'icse';

    public const ISC = 'isc';

    public const STATE_GENERIC = 'state.generic';

    /** All India schemes, CBSE classes 9-12 as the default. */
    public static function registry(): SchemeRegistry
    {
        return new SchemeRegistry([
            self::cbsePrimaryMiddle(),
            self::cbseSecondary(),
            self::icse(),
            self::isc(),
            self::stateGeneric(),
        ], self::CBSE_9_12);
    }

    /**
     * CBSE eight-band scholastic scale A1..E. [S1] prescribes it for classes VI-VIII;
     * CBSE prescribes no scale for classes I-V, so this preset is reused there (flagged
     * in VERIFICATION.md). No grade points are published for these classes [S1].
     */
    public static function cbsePrimaryMiddle(): PercentageScheme
    {
        return new PercentageScheme(self::CBSE_1_8, 'CBSE classes 1-8 (A1-E)', new GradeScale([
            new Band('A1', 91, 100),
            new Band('A2', 81, 90),
            new Band('B1', 71, 80),
            new Band('B2', 61, 70),
            new Band('C1', 51, 60),
            new Band('C2', 41, 50),
            new Band('D', 33, 40),
            new Band('E', 0, 32, null, 'Needs improvement', true),
        ]), 33.0, new Rounding(Rounding::HALF_UP, 0));
    }

    /**
     * CBSE nine-point absolute scale A1..E2 with grade points 10..4 [S2] (CCE-era class
     * IX-X scale, widely used by schools for internal grading in IX-XII). E1/E2 carry no
     * grade point and are fail bands. Pass = 33% [S3]. CBSE BOARD results (X, XII) use
     * rank-based grades instead — see positionalBoardGrader().
     */
    public static function cbseSecondary(): PercentageScheme
    {
        return new PercentageScheme(self::CBSE_9_12, 'CBSE classes 9-12 (nine-point A1-E2)', new GradeScale([
            new Band('A1', 91, 100, 10.0),
            new Band('A2', 81, 90, 9.0),
            new Band('B1', 71, 80, 8.0),
            new Band('B2', 61, 70, 7.0),
            new Band('C1', 51, 60, 6.0),
            new Band('C2', 41, 50, 5.0),
            new Band('D', 33, 40, 4.0),
            new Band('E1', 21, 32, null, 'Needs improvement', true),
            new Band('E2', 0, 20, null, 'Needs improvement', true),
        ]), 33.0, new Rounding(Rounding::HALF_UP, 0));
    }

    /**
     * ICSE (class X) and the council's class IX: pass mark 33% since the 2017 CISCE
     * revision [S4] (was 35%). CISCE certificates report marks; the council publishes
     * no letter-grade table, so this preset only has Pass / Fail bands — schools add
     * their own bands via config.
     */
    public static function icse(): PercentageScheme
    {
        return self::passFail(self::ICSE, 'ICSE (CISCE class 9-10)', 33.0);
    }

    /** ISC (class XII) and the council's class XI: pass mark 35% since the 2017 revision [S4] (was 40%). */
    public static function isc(): PercentageScheme
    {
        return self::passFail(self::ISC, 'ISC (CISCE class 11-12)', 35.0);
    }

    /**
     * A generic division scale many Indian state boards use in some form. It is an
     * EXAMPLE, not any one board's rule: thresholds vary by board and year (VERIFICATION.md).
     * State boards should be configured through config('grading.schemes').
     */
    public static function stateGeneric(): PercentageScheme
    {
        return new PercentageScheme(self::STATE_GENERIC, 'State board (configurable divisions)', new GradeScale([
            new Band('Distinction', 75, 100),
            new Band('First Division', 60, 74.99),
            new Band('Second Division', 45, 59.99),
            new Band('Third Division', 33, 44.99),
            new Band('Fail', 0, 32.99, null, null, true),
        ]), 33.0, new Rounding(Rounding::HALF_UP, 2));
    }

    /**
     * CBSE board-result grading: A1..D2 over eighths of PASSED candidates, E = fail [S5].
     * minimumCohort defaults to 0 (always positional); see VERIFICATION.md.
     */
    public static function positionalBoardGrader(int $minimumCohort = 0): PositionalGrader
    {
        return new PositionalGrader(['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'D1', 'D2'], 33.0, 'E', $minimumCohort);
    }

    /**
     * CBSE pass rule: 33% overall and 33% separately in theory and practical where the
     * subject has them [S3][S5]. Part names: "theory", "practical" (or "internal").
     */
    public static function cbsePassRule(): PassRule
    {
        return new PassRule(33.0, ['theory' => 33.0, 'practical' => 33.0], new Rounding(Rounding::HALF_UP, 0));
    }

    public static function icsePassRule(): PassRule
    {
        return new PassRule(33.0, [], new Rounding(Rounding::HALF_UP, 0));
    }

    public static function iscPassRule(): PassRule
    {
        return new PassRule(35.0, [], new Rounding(Rounding::HALF_UP, 0));
    }

    /** No public board grace rule is encoded: grace is OFF by default (VERIFICATION.md). */
    public static function gracePolicy(): GracePolicy
    {
        return GracePolicy::none();
    }

    /** CGPA x 9.5 indicative percentage over the best five subjects [S6]. */
    public static function cbseCgpa(): CgpaCalculator
    {
        return new CgpaCalculator(9.5, 5);
    }

    /** "Best of five" aggregate with English compulsory, common for ICSE results (VERIFICATION.md). */
    public static function icseBestOfFive(string $english = 'english'): BestOfAggregator
    {
        return new BestOfAggregator(5, [$english]);
    }

    /** ISC "best of four" with English compulsory — a convention, not a council rule (VERIFICATION.md). */
    public static function iscBestOfFour(string $english = 'english'): BestOfAggregator
    {
        return new BestOfAggregator(4, [$english]);
    }

    /**
     * CBSE per-term subject plan for classes VI-VIII [S1]: periodic test 10, notebook
     * submission 5, subject enrichment 5, half-yearly / yearly exam 80 = 100.
     */
    public static function cbseTermPlan(): WeightagePlan
    {
        return new WeightagePlan([
            new Component('periodic_test', 10, new Reduction(Reduction::AVERAGE), true, 'Periodic test'),
            new Component('notebook', 5, new Reduction(Reduction::AVERAGE), true, 'Notebook submission'),
            new Component('subject_enrichment', 5, new Reduction(Reduction::AVERAGE), true, 'Subject enrichment'),
            new Component('exam', 80, new Reduction(Reduction::AVERAGE), true, 'Term exam'),
        ]);
    }

    /**
     * CBSE classes IX-X subject plan: internal assessment 20 (periodic tests 10,
     * notebook 5, subject enrichment 5) + annual exam 80 [S1][S7]. Periodic tests use
     * "average of the best two" by default; the exact PT reduction has changed across
     * years, so $bestOf is a parameter (VERIFICATION.md).
     */
    public static function cbseSecondaryPlan(int $bestOf = 2): WeightagePlan
    {
        return new WeightagePlan([
            new Component('periodic_test', 10, Reduction::bestOf($bestOf), true, 'Periodic tests'),
            new Component('notebook', 5, new Reduction(Reduction::AVERAGE), true, 'Notebook submission'),
            new Component('subject_enrichment', 5, new Reduction(Reduction::AVERAGE), true, 'Subject enrichment'),
            new Component('annual_exam', 80, new Reduction(Reduction::AVERAGE), true, 'Annual exam'),
        ]);
    }

    /** Term combination; the 50/50 split is a school-level choice, not a CBSE rule (VERIFICATION.md). */
    public static function twoTermPlan(float $term1 = 50, float $term2 = 50): WeightagePlan
    {
        return new WeightagePlan([
            new Component('term1', $term1, new Reduction, true, 'Term 1'),
            new Component('term2', $term2, new Reduction, true, 'Term 2'),
        ]);
    }

    /** CBSE co-scholastic / discipline 3-point scale for classes VI-VIII [S1]. */
    public static function coScholasticThreePoint(): CoScholasticScale
    {
        return new CoScholasticScale('cbse.co-scholastic.3', [
            'A' => 'Outstanding',
            'B' => 'Very Good',
            'C' => 'Fair',
        ]);
    }

    /**
     * CBSE co-scholastic 5-point A-E scale for class IX [S1]. The circular names the
     * scale but not per-grade descriptors, so descriptors are null (VERIFICATION.md).
     */
    public static function coScholasticFivePoint(): CoScholasticScale
    {
        return new CoScholasticScale('cbse.co-scholastic.5', [
            'A' => null, 'B' => null, 'C' => null, 'D' => null, 'E' => null,
        ]);
    }

    private static function passFail(string $key, string $name, float $pass): PercentageScheme
    {
        return new PercentageScheme($key, $name, new GradeScale([
            new Band('Pass', $pass, 100),
            new Band('Fail', 0, $pass - 0.01, null, null, true),
        ]), $pass, new Rounding(Rounding::HALF_UP, 0));
    }
}
