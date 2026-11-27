<?php

declare(strict_types=1);

namespace Classivo\Grading;

/**
 * A rounding rule applied to a percentage or mark before it is looked up or compared.
 *
 * Boards rarely publish their internal rounding, so every scheme takes one of these
 * instead of hard-coding a rule. Default: half-up to 0 decimals (the common school
 * practice of rounding 32.5 to 33).
 */
final class Rounding
{
    public const NONE = 'none';

    public const HALF_UP = 'half_up';

    public const HALF_EVEN = 'half_even';

    public const UP = 'up';

    public const DOWN = 'down';

    private const MODES = [self::NONE, self::HALF_UP, self::HALF_EVEN, self::UP, self::DOWN];

    public function __construct(
        public readonly string $mode = self::HALF_UP,
        public readonly int $precision = 0,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidConfiguration("Unknown rounding mode [{$mode}]. Use one of: ".implode(', ', self::MODES).'.');
        }
        if ($precision < 0 || $precision > 6) {
            throw new InvalidConfiguration('Rounding precision must be between 0 and 6.');
        }
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** @param array{mode?: string, precision?: int}|null $config */
    public static function fromArray(?array $config): self
    {
        if ($config === null) {
            return new self;
        }

        return new self($config['mode'] ?? self::HALF_UP, (int) ($config['precision'] ?? 0));
    }

    public function apply(float $value): float
    {
        $factor = 10 ** $this->precision;
        // Strip binary noise (e.g. 32.499999999) before directional rounding.
        $scaled = round($value * $factor, 9);

        return match ($this->mode) {
            self::NONE => $value,
            self::HALF_UP => round($scaled, 0, PHP_ROUND_HALF_UP) / $factor,
            self::HALF_EVEN => round($scaled, 0, PHP_ROUND_HALF_EVEN) / $factor,
            self::UP => ceil($scaled) / $factor,
            self::DOWN => floor($scaled) / $factor,
        };
    }

    /** @return array{mode: string, precision: int} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'precision' => $this->precision];
    }
}
