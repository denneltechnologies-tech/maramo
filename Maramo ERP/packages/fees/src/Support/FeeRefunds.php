<?php

namespace Classivo\Fees\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * FeeRefunds — the vocabulary and the policy for fee refunds, in one place.
 *
 * Two jobs, both of which used to be nowhere:
 *
 * ── THE REASON CODES ─────────────────────────────────────────────────────────
 * Every reason in the fee module is free text: `fee_payments.revert_reason`,
 * `fee_payment_details.discount_reason`. Free text records WHAT one clerk typed
 * and answers nothing afterwards — "how much did we refund for cancelled
 * admissions this term" is not a query you can write against it. The five codes
 * below are the cases schools actually hit, and each one carries a default for
 * whether refunding it should re-open the student's dues, because that answer is
 * a property of the REASON and not of the operator's mood:
 *
 *   tc_issued           — a Transfer Certificate was issued mid-year. The unused
 *                         terms come back and the dues come back with them.
 *   admission_cancelled — the admission was cancelled. Same.
 *   wrong_head          — paid against the wrong fee head. The wrong head's dues
 *                         re-open so the money can be re-collected against the
 *                         right one.
 *   excess_payment      — the family simply paid too much. There is NO debt to
 *                         restore; re-opening dues here would invent one, which
 *                         is why this code alone defaults `restore_dues` false.
 *   hostel_departure    — left the hostel mid-term. The hostel head re-opens and
 *                         the office then re-prices the allocation pro-rata.
 *
 * A code is required; the free-text detail sits BESIDE it, never instead of it.
 *
 * ── THE APPROVAL POLICY ──────────────────────────────────────────────────────
 * How many approvals a refund of a given amount needs, read from config
 * `fees.refunds.*` (with the defaults below when a key is unset).
 */
class FeeRefunds
{
    /** Fallbacks when a config key is unset. */
    public const DEFAULT_THRESHOLD = 5000.0;

    public const DEFAULT_SECOND_THRESHOLD = 25000.0;

    public const DEFAULT_PREFIX = 'CN';

    public const DEFAULT_START = 1;

    /** The reason-code vocabulary: code => label. */
    public const REASON_CODES = [
        'tc_issued' => 'TC issued',
        'admission_cancelled' => 'Admission cancelled',
        'wrong_head' => 'Wrong head paid',
        'excess_payment' => 'Excess payment',
        'hostel_departure' => 'Mid-term hostel departure',
        'gateway_refund' => 'Refunded through the payment gateway',
    ];

    /**
     * Whether a code's refund re-opens the student's dues by default. The
     * operator may still override per line — a TC refund that also returns an
     * unallocated over-payment restores the first and not the second — but the
     * default should be right without anyone thinking about it.
     */
    public const RESTORES_DUES = [
        'tc_issued' => true,
        'admission_cancelled' => true,
        'wrong_head' => true,
        'excess_payment' => false,
        'hostel_departure' => true,
        'gateway_refund' => true,
    ];

    /** How the money goes back: mode => label. */
    public const MODES = [
        'original' => 'Back to original payment method',
        'cheque' => 'Cheque',
        'bank_transfer' => 'Bank transfer',
    ];

    /** Roles that keep access when a permission was never seeded (see AdminModules / ApprovalRegistry). */

    /** Human label for a stored code, falling back to the raw code. */
    public static function reasonLabel(?string $code): string
    {
        return self::REASON_CODES[$code] ?? (string) $code;
    }

    /** Human label for a stored payout mode. */
    public static function modeLabel(?string $mode): string
    {
        return self::MODES[$mode] ?? ucfirst(str_replace('_', ' ', (string) $mode));
    }

    /** Does this reason code re-open dues by default? Unknown codes: yes (the safe side). */
    public static function restoresDues(?string $code): bool
    {
        return self::RESTORES_DUES[$code] ?? true;
    }

    /** The school's auto-approval threshold in rupees (never negative). */
    public static function threshold(): float
    {
        return max(0, (float) self::setting('approval_threshold', self::DEFAULT_THRESHOLD));
    }

    /** The school's two-approver threshold in rupees (never negative). */
    public static function secondThreshold(): float
    {
        return max(0, (float) self::setting('second_approval_threshold', self::DEFAULT_SECOND_THRESHOLD));
    }

    /** The credit-note prefix, trimmed and never empty. */
    public static function creditNotePrefix(): string
    {
        $prefix = trim((string) self::setting('credit_note_prefix', self::DEFAULT_PREFIX));

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    /** The first number in the credit-note series (never below 1). */
    public static function creditNoteStart(): int
    {
        return max(1, (int) self::setting('credit_note_start', self::DEFAULT_START));
    }

    /**
     * How many approvals a refund of $amount needs.
     *
     * 0 = auto-approved at request time (still logged as a decision).
     * 1 = one approver above the threshold.
     * 2 = two DISTINCT approvers above the second threshold.
     *
     * The second threshold is only honoured when it is genuinely above the
     * first: setting it equal to or below the first is how an administrator
     * switches the second level off, and reading it literally there would demand
     * two approvals for every approvable refund.
     */
    public static function requiredApprovals(float $amount): int
    {
        $amount = round($amount, 2);
        $first = self::threshold();
        $second = self::secondThreshold();

        if ($amount <= $first) {
            return 0;
        }

        return ($second > $first && $amount > $second) ? 2 : 1;
    }

    /** The ability check used by every refund surface (deny by default). */
    public static function allows(?Authenticatable $user, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        return method_exists($user, 'can') && $user->can($ability);
    }

    /**
     * One settings read, defended.
     *
     * @return mixed the stored value, or $default when settings are unreadable
     */
    protected static function setting(string $key, mixed $default): mixed
    {
        return config('fees.refunds.'.$key) ?? $default;
    }
}
