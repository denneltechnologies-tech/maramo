<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Scale\Band;
use Classivo\Grading\Scale\GradeScale;
use PHPUnit\Framework\TestCase;

final class GradeScaleTest extends TestCase
{
    private function scale(string $policy = GradeScale::THRESHOLD): GradeScale
    {
        return new GradeScale([
            new Band('C', 33, 40),
            new Band('A', 81, 100, 9.0),
            new Band('B', 41, 80),
            new Band('F', 0, 32, null, null, true),
        ], $policy);
    }

    public function test_bands_are_sorted_best_first(): void
    {
        self::assertSame(['A', 'B', 'C', 'F'], $this->scale()->labels());
    }

    public function test_threshold_closes_gaps(): void
    {
        $s = $this->scale();
        self::assertSame('C', $s->find(40.5)?->label);
        self::assertSame('F', $s->find(32.9)?->label);
        self::assertSame('A', $s->find(100)?->label);
        self::assertSame('F', $s->find(0)?->label);
    }

    public function test_strict_leaves_gaps(): void
    {
        $s = $this->scale(GradeScale::STRICT);
        self::assertNull($s->find(40.5));
        self::assertSame('C', $s->find(40)?->label);
        self::assertSame('B', $s->find(41)?->label);
    }

    public function test_by_label_and_lowest_passing_min(): void
    {
        $s = $this->scale();
        self::assertSame(9.0, $s->byLabel('A')?->gradePoint);
        self::assertNull($s->byLabel('Z'));
        self::assertSame(33.0, $s->lowestPassingMin());
    }

    public function test_lowest_passing_min_null_when_all_fail(): void
    {
        self::assertNull((new GradeScale([new Band('X', 0, 100, null, null, true)]))->lowestPassingMin());
    }

    public function test_touching_edges_allowed(): void
    {
        $s = new GradeScale([new Band('Hi', 40, 100), new Band('Lo', 0, 40)]);
        self::assertSame('Hi', $s->find(40)?->label);
    }

    public function test_overlap_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new GradeScale([new Band('Hi', 40, 100), new Band('Lo', 0, 50)]);
    }

    public function test_duplicate_label_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new GradeScale([new Band('A', 50, 100), new Band('A', 0, 49)]);
    }

    public function test_same_min_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new GradeScale([new Band('A', 50, 100), new Band('B', 50, 50)]);
    }

    public function test_empty_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new GradeScale([]);
    }

    public function test_band_validation(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Band('A', 60, 50);
    }

    public function test_band_out_of_range(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Band('A', 50, 101);
    }

    public function test_from_array_round_trip(): void
    {
        $rows = [
            ['label' => 'P', 'min' => '33', 'max' => '100', 'grade_point' => '', 'remark' => 'ok'],
            ['name' => 'F', 'min' => 0, 'max' => 32.99, 'is_fail' => '1'],
        ];
        $s = GradeScale::fromArray($rows);
        self::assertTrue($s->byLabel('F')?->isFail);
        self::assertNull($s->byLabel('P')?->gradePoint);
        self::assertSame('ok', $s->byLabel('P')?->remark);
        self::assertSame(GradeScale::fromArray($s->toArray())->toArray(), $s->toArray());
    }
}
