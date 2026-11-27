<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use ArrayObject;
use Classivo\Grading\Adapters\GradeTableAdapter;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Presets\India\IndiaPreset;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\GradeScale;
use PHPUnit\Framework\TestCase;

final class GradeTableAdapterTest extends TestCase
{
    /** Rows shaped like the `grades` table (decimal casts arrive as strings). */
    private function rows(): array
    {
        return [
            ['name' => 'A', 'min_percent' => '80.00', 'max_percent' => '100.00', 'grade_point' => '4.00', 'remark' => 'Excellent', 'is_fail' => false],
            (object) ['name' => 'B', 'min_percent' => '60.00', 'max_percent' => '79.00', 'grade_point' => '3.00', 'remark' => null, 'is_fail' => 0],
            new ArrayObject(['name' => 'C', 'min_percent' => '40.00', 'max_percent' => '59.00', 'grade_point' => null, 'remark' => '', 'is_fail' => '0']),
            ['name' => 'F', 'min_percent' => '0.00', 'max_percent' => '39.00', 'grade_point' => '0.00', 'remark' => 'Fail', 'is_fail' => true],
        ];
    }

    public function test_maps_rows_to_strict_scheme(): void
    {
        $s = GradeTableAdapter::toScheme($this->rows(), 'school.7');
        self::assertSame('school.7', $s->key());
        self::assertSame(40.0, $s->passPercentage());
        self::assertSame(GradeScale::STRICT, $s->scale()->gapPolicy);
        self::assertSame(Rounding::NONE, $s->rounding()->mode);

        self::assertSame('A', $s->grade(80)->label());
        self::assertSame(4.0, $s->grade(80)->gradePoint());
        self::assertSame('Excellent', $s->grade(90)->remark());
        self::assertNull($s->grade(59.5)->label()); // gap, like Grade::forPercentage()
        self::assertNull($s->grade(45)->gradePoint());
        self::assertNull($s->grade(45)->remark());
        self::assertFalse($s->grade(20)->passed);
        self::assertSame(0.0, $s->grade(20)->gradePoint());
    }

    public function test_threshold_and_rounding_options(): void
    {
        $s = GradeTableAdapter::toScheme($this->rows(), 'x', 'X', 35.0, new Rounding(Rounding::HALF_UP), GradeScale::THRESHOLD);
        self::assertSame('C', $s->grade(59.4)->label());
        self::assertSame('B', $s->grade(59.5)->label());
        self::assertSame(35.0, $s->passPercentage());
        self::assertSame('X', $s->name());
        self::assertFalse($s->grade(36)->passed); // still in the fail band
    }

    public function test_row_without_name_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        GradeTableAdapter::toScale([['min_percent' => 0, 'max_percent' => 100]]);
    }

    public function test_non_row_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        GradeTableAdapter::toScale(['nope']);
    }

    public function test_preset_to_rows_round_trip(): void
    {
        $rows = GradeTableAdapter::toRows(IndiaPreset::cbseSecondary()->scale());
        self::assertCount(9, $rows);
        self::assertSame(['name' => 'A1', 'min_percent' => 91.0, 'max_percent' => 100.0, 'grade_point' => 10.0, 'remark' => null, 'is_fail' => false], $rows[0]);
        $back = GradeTableAdapter::toScale($rows, GradeScale::THRESHOLD);
        self::assertSame(IndiaPreset::cbseSecondary()->scale()->toArray(), $back->toArray());
    }
}
