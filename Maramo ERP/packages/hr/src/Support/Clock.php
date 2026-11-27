<?php

namespace Classivo\Hr\Support;

use Classivo\Core\Support\Preset;
use Illuminate\Support\Carbon;

/** "Now" in the school's wall-clock timezone (from the regional preset). */
class Clock
{
    public static function timezone(): string
    {
        return (string) (config('classivo.timezone') ?: Preset::get('timezone', config('app.timezone', 'UTC')));
    }

    public static function now(): Carbon
    {
        return Carbon::now(static::timezone());
    }
}
