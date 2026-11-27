<?php

namespace Classivo\Hr\Support;

use Classivo\Core\Support\Preset;

/** Currency of the active regional preset (India preset: INR / ₹). */
class Currency
{
    public static function code(): string
    {
        return (string) Preset::get('currency', 'USD');
    }

    public static function symbol(): string
    {
        return (string) Preset::get('currency_symbol', '$');
    }
}
