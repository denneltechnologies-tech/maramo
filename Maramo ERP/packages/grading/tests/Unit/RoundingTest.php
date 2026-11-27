<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundingTest extends TestCase
{
    /** @return array<string, array{string, int, float, float}> */
    public static function cases(): array
    {
        return [
            'half up .5' => [Rounding::HALF_UP, 0, 32.5, 33.0],
            'half up below' => [Rounding::HALF_UP, 0, 32.49, 32.0],
            'half even .5 to even' => [Rounding::HALF_EVEN, 0, 32.5, 32.0],
            'half even 33.5' => [Rounding::HALF_EVEN, 0, 33.5, 34.0],
            'up' => [Rounding::UP, 0, 32.01, 33.0],
            'up exact stays' => [Rounding::UP, 0, 33.0, 33.0],
            'up float noise' => [Rounding::UP, 2, 0.1 + 0.2, 0.3],
            'down' => [Rounding::DOWN, 0, 32.99, 32.0],
            'down precision' => [Rounding::DOWN, 1, 89.99, 89.9],
            'none' => [Rounding::NONE, 0, 32.456, 32.456],
            'half up precision 2' => [Rounding::HALF_UP, 2, 66.665, 66.67],
        ];
    }

    #[DataProvider('cases')]
    public function test_apply(string $mode, int $precision, float $in, float $out): void
    {
        self::assertEqualsWithDelta($out, (new Rounding($mode, $precision))->apply($in), 1e-9);
    }

    public function test_from_array_defaults_to_half_up_whole(): void
    {
        $r = Rounding::fromArray(null);
        self::assertSame(Rounding::HALF_UP, $r->mode);
        self::assertSame(0, $r->precision);
        self::assertSame(['mode' => 'down', 'precision' => 1], Rounding::fromArray(['mode' => 'down', 'precision' => 1])->toArray());
    }

    public function test_rejects_unknown_mode(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Rounding('banker');
    }

    public function test_rejects_bad_precision(): void
    {
        $this->expectException(InvalidConfiguration::class);
        new Rounding(Rounding::UP, 7);
    }
}
