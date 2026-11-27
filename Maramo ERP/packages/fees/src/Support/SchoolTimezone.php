<?php

namespace Classivo\Fees\Support;

use Carbon\CarbonImmutable;
use Classivo\Core\Support\Preset;

/**
 * The school's wall-clock timezone for "is it the same school day?" decisions.
 * Storage stays in the app timezone; this is display/decision only.
 * Order: current branch timezone -> preset timezone -> app timezone.
 */
final class SchoolTimezone
{
    public static function resolve(): string
    {
        $candidates = [];

        try {
            $candidates[] = function_exists('current_branch') ? current_branch()?->timezone : null;
        } catch (\Throwable) {
            // no branch context
        }

        $candidates[] = Preset::get('timezone');
        $candidates[] = config('app.timezone', 'UTC');

        foreach ($candidates as $tz) {
            if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        }

        return 'UTC';
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::resolve());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }
}
