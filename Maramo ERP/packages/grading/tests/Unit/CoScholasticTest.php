<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Unit;

use Classivo\Grading\CoScholastic\CoScholasticScale;
use Classivo\Grading\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class CoScholasticTest extends TestCase
{
    private function scale(): CoScholasticScale
    {
        return CoScholasticScale::fromArray([
            'key' => 'school.co',
            'grades' => ['A' => 'Outstanding', 'B' => 'Very Good', 'C' => 'Fair'],
            'bands' => [
                ['label' => 'A', 'min' => 75, 'max' => 100],
                ['label' => 'B', 'min' => 50, 'max' => 74.99],
                ['label' => 'C', 'min' => 0, 'max' => 49.99],
            ],
        ]);
    }

    public function test_validate_normalises_case(): void
    {
        self::assertSame('A', $this->scale()->validate(' a '));
        self::assertSame('Very Good', $this->scale()->describe('b'));
        self::assertSame(['A', 'B', 'C'], $this->scale()->labels());
        self::assertFalse($this->scale()->isValid('D'));
    }

    public function test_validate_rejects_unknown(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->scale()->validate('D');
    }

    public function test_lowercase_labels_supported(): void
    {
        $s = new CoScholasticScale('x', ['good' => null, 'fair' => null]);
        self::assertSame('good', $s->validate('good'));
    }

    public function test_from_percentage(): void
    {
        self::assertSame('A', $this->scale()->fromPercentage(80));
        self::assertSame('C', $this->scale()->fromPercentage(10));
    }

    public function test_from_percentage_without_bands(): void
    {
        $this->expectException(InvalidConfiguration::class);
        (new CoScholasticScale('x', ['A' => null]))->fromPercentage(50);
    }

    public function test_band_labels_must_exist(): void
    {
        $this->expectException(InvalidConfiguration::class);
        CoScholasticScale::fromArray(['key' => 'x', 'grades' => ['A' => null], 'bands' => [['label' => 'Z', 'min' => 0]]]);
    }
}
