<?php

namespace Classivo\Exams\Support;

use Illuminate\Support\Facades\Gate;

/**
 * Ability check used by the marks register and publish controls. Always goes through
 * the Gate; ExamsServiceProvider registers a deny-by-default Gate that checks the
 * spatie/laravel-permission permission of the same name, unless the host app defined
 * its own Gate for the ability.
 */
class Authorize
{
    public static function can(string $key): bool
    {
        $ability = (string) config("classivo-exams.abilities.{$key}", '');

        return $ability !== '' && Gate::allows($ability);
    }
}
