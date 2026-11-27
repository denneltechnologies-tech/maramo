<?php

namespace Classivo\Fees\Support;

/**
 * DiscountApprovalPolicy — when a fee concession must be approved before it
 * reduces what a student owes.
 *
 * Read from config `fees.concessions.*` (requires_approval, threshold_amount,
 * threshold_percent); an unset key falls back to the DEFAULT_* constants, which
 * mean "no gate".
 *
 * Two independent tests, either of which can trip; a zero disables its own test
 * and never the other one:
 *
 *   • AMOUNT   — the concession amount, STRICTLY greater than the ceiling.
 *   • PERCENT  — the concession as a share of the fee it is written against,
 *                STRICTLY greater than the ceiling. Skipped when the base is 0.
 */
final class DiscountApprovalPolicy
{
    /** Fallbacks when a config key is unset. All mean "no gate". */
    public const DEFAULT_ENABLED = false;

    public const DEFAULT_AMOUNT = 0.0;

    public const DEFAULT_PERCENT = 0.0;

    /**
     * The stored switch, exactly as the administrator left it.
     *
     * Distinct from {@see enabled()} on purpose: the settings screen must render
     * the switch the operator set, not the switch's EFFECT. Reading enabled() back
     * into that toggle would silently turn it off again for anyone who saved it
     * before filling in a limit.
     */
    public static function switchedOn(): bool
    {
        return (bool) (self::setting('requires_approval') ?? self::DEFAULT_ENABLED);
    }

    /** Is the school actually gating concessions right now? */
    public static function enabled(): bool
    {
        // A gate switched on with both ceilings at zero would send EVERY
        // concession for approval, including a ₹1 rounding courtesy. That is
        // never what an administrator meant by turning the switch on without
        // filling the boxes in, so it reads as "not configured yet".
        return self::switchedOn()
            && (self::amountCeiling() > 0 || self::percentCeiling() > 0);
    }

    /** Money ceiling in major units; 0 = the amount test is off. */
    public static function amountCeiling(): float
    {
        return max(0.0, round((float) (self::setting('threshold_amount') ?? self::DEFAULT_AMOUNT), 2));
    }

    /** Percentage ceiling 0–100; 0 = the percentage test is off. */
    public static function percentCeiling(): float
    {
        return min(100.0, max(0.0, round((float) (self::setting('threshold_percent') ?? self::DEFAULT_PERCENT), 2)));
    }

    /**
     * Does a concession of `$amount`, written against a fee of `$base`, need an
     * approval before it may reduce what the student owes?
     */
    public static function requiresApproval(float $amount, float $base = 0.0): bool
    {
        return self::breach($amount, $base) !== null;
    }

    /**
     * The reason this concession is over the line, or NULL when it is within the
     * school's limits.
     *
     * Returned as a finished sentence because the panel notification, the API 422
     * and the approval row's own remark must all say the same thing — an operator
     * who is told "over the limit" on one screen and "needs approval" on another
     * will reasonably believe they are two different problems.
     */
    public static function breach(float $amount, float $base = 0.0): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $amount = round(max(0.0, $amount), 2);

        if ($amount <= 0) {
            return null;
        }

        $amountCeiling = self::amountCeiling();

        if ($amountCeiling > 0 && $amount > $amountCeiling) {
            return 'This concession of '.self::money($amount)
                .' is above the school\'s limit of '.self::money($amountCeiling)
                .', so it needs approval before it can reduce what the student owes.';
        }

        $percentCeiling = self::percentCeiling();
        $base = round(max(0.0, $base), 2);

        if ($percentCeiling > 0 && $base > 0) {
            $share = round($amount / $base * 100, 2);

            if ($share > $percentCeiling) {
                return 'This concession is '.self::trim($share).'% of the fee, above the school\'s limit of '
                    .self::trim($percentCeiling).'%, so it needs approval before it can reduce what the student owes.';
            }
        }

        return null;
    }

    /**
     * The percentage test on its own, for a concession expressed only as a share.
     *
     * A RECURRING standing instruction ("this child holds a 50% staff-ward
     * concession") has no rupee figure at the moment it is granted — the money is
     * only knowable per billing cycle, against each allocation as it is raised. Its
     * share, however, is knowable exactly, and it is the honest thing to weigh: a
     * 50% standing concession is a bigger decision than a ₹200 one whatever the
     * term fee turns out to be. Running the ordinary {@see breach()} on it would
     * compare the number 50 against a RUPEE ceiling and refuse or allow it for
     * entirely the wrong reason, so the amount test is skipped rather than misread.
     */
    public static function breachOfShare(float $share): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $ceiling = self::percentCeiling();
        $share = round(max(0.0, $share), 2);

        if ($ceiling <= 0 || $share <= $ceiling) {
            return null;
        }

        return 'This concession is '.self::trim($share).'% of every fee it applies to, above the school\'s limit of '
            .self::trim($ceiling).'%, so it needs approval before it can reduce what the student owes.';
    }

    /** One line describing the live policy — for settings help text and the register. */
    public static function summary(): string
    {
        if (! self::enabled()) {
            return 'No approval is required for concessions — any amount takes effect immediately.';
        }

        $parts = [];

        if (self::amountCeiling() > 0) {
            $parts[] = 'over '.self::money(self::amountCeiling());
        }

        if (self::percentCeiling() > 0) {
            $parts[] = 'over '.self::trim(self::percentCeiling()).'% of the fee';
        }

        return 'A concession '.implode(' or ', $parts).' needs approval before it reduces what the student owes.';
    }

    /** One policy value from config `fees.concessions.*`. */
    private static function setting(string $key): mixed
    {
        return config('fees.concessions.'.$key);
    }

    /** 25.00 → "25", 12.50 → "12.5". Percentages read badly with dead zeros. */
    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Money for a sentence a school administrator reads.
     *
     * {@see Money} deals in minor units for the wire and exposes the school's own
     * currency symbol; it has no display formatter, so the symbol comes from there
     * and the grouping from number_format rather than a second currency notion
     * being invented here.
     */
    private static function money(float $value): string
    {
        return Money::symbol().number_format($value, 2);
    }
}
