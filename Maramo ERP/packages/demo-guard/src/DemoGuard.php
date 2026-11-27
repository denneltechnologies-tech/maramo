<?php

namespace Classivo\DemoGuard;

use Classivo\DemoGuard\Exceptions\DemoRestricted;

final class DemoGuard
{
    private static int $bypass = 0;

    public static function enabled(): bool
    {
        $flag = config('demo-guard.enabled');

        return $flag === null ? app()->environment('demo') : (bool) $flag;
    }

    /** Run a callback with the guard off (tests, internal jobs). */
    public static function bypass(callable $callback): mixed
    {
        self::$bypass++;
        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    public static function active(): bool
    {
        if (self::$bypass > 0 || ! self::enabled()) {
            return false;
        }

        return ! (config('demo-guard.allow_console', true) && app()->runningInConsole());
    }

    public static function deny(?string $what = null): never
    {
        throw new DemoRestricted($what);
    }
}
