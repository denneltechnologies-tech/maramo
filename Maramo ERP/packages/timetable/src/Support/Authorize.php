<?php

namespace Classivo\Timetable\Support;

use Illuminate\Support\Facades\Gate;

/**
 * Ability check for the timetable screens ('view', 'edit', 'generate'). Always
 * goes through the Gate; TimetableServiceProvider registers deny-by-default
 * Gates backed by spatie/laravel-permission unless the host app defined its own.
 */
class Authorize
{
    public static function can(string $key): bool
    {
        $ability = (string) config("classivo-timetable.abilities.{$key}", '');

        return $ability !== '' && Gate::allows($ability);
    }
}
