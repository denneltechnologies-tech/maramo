<?php

namespace Classivo\Fees\Support;

use Classivo\Core\Models\Branch;
use Classivo\Core\Support\Preset;

/**
 * Currency for fee documents and messages. Resolution order:
 *   1. the branch's own `currency` / `currency_symbol`,
 *   2. config `fees.currency` / `fees.currency_symbol` (when set),
 *   3. the core regional preset (India preset = INR / ₹).
 */
final class Money
{
    public static function code(?Branch $branch = null): string
    {
        $branch ??= self::currentBranch();

        return (string) ($branch?->currency
            ?: config('fees.currency')
            ?: Preset::get('currency', 'INR'));
    }

    public static function symbol(?Branch $branch = null): string
    {
        $branch ??= self::currentBranch();

        return (string) ($branch?->currency_symbol
            ?: config('fees.currency_symbol')
            ?: Preset::get('currency_symbol', '₹'));
    }

    public static function format(float|int|string|null $amount, ?Branch $branch = null): string
    {
        return self::symbol($branch).number_format((float) $amount, 2);
    }

    private static function currentBranch(): ?Branch
    {
        try {
            return function_exists('current_branch') ? current_branch() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
