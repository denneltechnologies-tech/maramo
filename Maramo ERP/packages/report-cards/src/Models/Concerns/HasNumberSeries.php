<?php

namespace Classivo\ReportCards\Models\Concerns;

/** Prefix + zero-padded running counter, unique per school, soft-deleted rows counted. */
trait HasNumberSeries
{
    protected static function nextInSeries(string $column, string $prefix, int $digits, ?int $schoolId = null): string
    {
        $schoolId ??= current_school_id();

        $max = static::withoutGlobalScopes()
            ->withTrashed()
            ->where('school_id', $schoolId)
            ->where($column, 'like', $prefix.'%')
            ->pluck($column)
            ->map(function ($number) use ($prefix): int {
                $suffix = substr((string) $number, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max();

        return $prefix.str_pad((string) (((int) $max) + 1), $digits, '0', STR_PAD_LEFT);
    }
}
