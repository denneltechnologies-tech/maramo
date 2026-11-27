<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\Band;
use Classivo\Grading\Scale\GradeScale;
use Classivo\Grading\Schemes\PercentageScheme;
use Classivo\Grading\Schemes\SchemeRegistry;
use PHPUnit\Framework\TestCase;

final class PercentageSchemeTest extends TestCase
{
    private function scheme(?Rounding $r = null): PercentageScheme
    {
        return new PercentageScheme('t', 'Test', new GradeScale([
            new Band('P', 40, 100, 1.0, 'Pass'),
            new Band('F', 0, 39.99, null, null, true),
        ]), 40.0, $r ?? new Rounding);
    }

    public function test_rounding_decides_pass(): void
    {
        $r = $this->scheme()->grade(39.5);
        self::assertTrue($r->passed);
        self::assertSame(40.0, $r->roundedPercentage);
        self::assertSame(39.5, $r->percentage);
        self::assertSame('P', $r->label());

        self::assertFalse($this->scheme(new Rounding(Rounding::DOWN))->grade(39.5)->passed);
    }

    public function test_fail_band_fails_even_above_pass_mark(): void
    {
        $s = new PercentageScheme('t', 'T', new GradeScale([
            new Band('A', 50, 100),
            new Band('X', 30, 49.99, null, null, true),
            new Band('F', 0, 29.99, null, null, true),
        ]), 30.0);
        self::assertFalse($s->grade(35)->passed);
        self::assertTrue($s->grade(50)->passed);
    }

    public function test_grade_marks_and_to_array(): void
    {
        $r = $this->scheme()->gradeMarks(18, 40);
        self::assertSame(45.0, $r->percentage);
        self::assertSame(['scheme' => 't', 'percentage' => 45.0, 'rounded_percentage' => 45.0, 'grade' => 'P', 'grade_point' => 1.0, 'remark' => 'Pass', 'passed' => true], $r->toArray());
    }

    public function test_out_of_range_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->scheme()->grade(100.1);
    }

    public function test_marks_above_max_rejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->scheme()->gradeMarks(41, 40);
    }

    public function test_from_array_derives_pass_from_bands(): void
    {
        $s = PercentageScheme::fromArray([
            'key' => 'state.x',
            'rounding' => ['mode' => 'none'],
            'bands' => [
                ['label' => 'I', 'min' => 60, 'max' => 100],
                ['label' => 'II', 'min' => 45, 'max' => 59.99],
                ['label' => 'Fail', 'min' => 0, 'max' => 44.99, 'is_fail' => true],
            ],
        ]);
        self::assertSame(45.0, $s->passPercentage());
        self::assertSame('state.x', $s->name());
        self::assertFalse($s->grade(44.995)->passed);
        self::assertSame($s->toArray(), PercentageScheme::fromArray($s->toArray())->toArray());
    }

    public function test_from_array_needs_bands(): void
    {
        $this->expectException(InvalidConfiguration::class);
        PercentageScheme::fromArray(['key' => 'x', 'bands' => []]);
    }

    public function test_registry(): void
    {
        $reg = new SchemeRegistry([$this->scheme()]);
        self::assertSame('t', $reg->defaultKey());
        self::assertSame('t', $reg->get()->key());
        self::assertTrue($reg->has('t'));
        $other = new PercentageScheme('u', 'U', new GradeScale([new Band('A', 0, 100)]), 0);
        $reg->register($other)->setDefault('u');
        self::assertSame('u', $reg->get()->key());
        self::assertSame(['t', 'u'], $reg->keys());
        self::assertCount(2, $reg->all());
    }

    public function test_registry_unknown(): void
    {
        $this->expectException(InvalidConfiguration::class);
        (new SchemeRegistry)->get('nope');
    }

    public function test_registry_unknown_default(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new SchemeRegistry([$this->scheme()], 'nope');
    }
}
