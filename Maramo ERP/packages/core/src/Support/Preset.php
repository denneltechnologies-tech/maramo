<?php

namespace Classivo\Core\Support;

/**
 * Read access to the active regional preset (config/classivo.php `presets`).
 * The core never hard-codes a country; it asks the preset for defaults.
 */
class Preset
{
    public static function name(): string
    {
        return (string) config('classivo.preset', 'india');
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return (array) config('classivo.presets.'.static::name(), []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all()[$key] ?? $default;
    }
}
