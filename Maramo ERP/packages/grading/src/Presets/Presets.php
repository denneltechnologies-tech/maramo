<?php

declare(strict_types=1);

namespace Classivo\Grading\Presets;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Presets\India\IndiaPreset;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\Band;
use Classivo\Grading\Scale\GradeScale;
use Classivo\Grading\Schemes\PercentageScheme;
use Classivo\Grading\Schemes\SchemeRegistry;

/**
 * Preset entry point. The core is country-neutral; "india" is the default preset.
 *
 *   Presets::make('india')   -> CBSE / ICSE / ISC / state schemes (default cbse.9-12)
 *   Presets::make('neutral') -> one plain A-F percentage scheme, nothing country-specific
 */
final class Presets
{
    public const DEFAULT = 'india';

    public static function make(?string $name = null): SchemeRegistry
    {
        return match ($name ?? self::DEFAULT) {
            'india' => IndiaPreset::registry(),
            'neutral', 'none' => new SchemeRegistry([self::neutralScheme()]),
            default => throw new InvalidConfiguration("Unknown grading preset [{$name}]. Use india or neutral."),
        };
    }

    /**
     * A plain letter scale with no country rule behind it; pass at 40%. Intended as a
     * starting point to copy into config, not as any jurisdiction's standard.
     */
    public static function neutralScheme(): PercentageScheme
    {
        return new PercentageScheme('generic.letter', 'Generic letter grades (A-F)', new GradeScale([
            new Band('A', 80, 100, 4.0),
            new Band('B', 70, 79.99, 3.0),
            new Band('C', 60, 69.99, 2.0),
            new Band('D', 50, 59.99, 1.0),
            new Band('E', 40, 49.99, 0.5),
            new Band('F', 0, 39.99, 0.0, null, true),
        ]), 40.0, new Rounding(Rounding::HALF_UP, 2));
    }
}
