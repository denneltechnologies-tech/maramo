<?php

namespace Classivo\Fees\Support;

/**
 * Permission names used by the fees screens and services. They are plain
 * Laravel ability strings, so they work with spatie/laravel-permission (create
 * them with FeesPermissionSeeder and assign them to roles) or with your own
 * Gate definitions.
 *
 * Deny by default: a user without the permission cannot see or use the screen.
 * When spatie/laravel-permission is NOT installed, the service provider defines
 * each ability as "the user has a truthy `is_admin` attribute" (config
 * `fees.define_gates`); with spatie installed nothing is defined, so only
 * explicitly granted permissions pass.
 */
final class FeesPermissions
{
    /** Fee types, groups, masters, fine rules and the concession catalogue. */
    public const SETUP = 'fees.setup';

    /** Assign fee masters to a class. */
    public const ASSIGN = 'fees.assign';

    /** See receipts. */
    public const VIEW = 'fees.view';

    /** Take a payment at the counter; review offline payments. */
    public const COLLECT = 'fees.collect';

    /** Revert a whole receipt. */
    public const REVERT = 'fees.revert';

    /** Grant / approve concessions, discount at the counter, override a late fine. */
    public const DISCOUNT = 'fees.discount';

    /** Raise and view refunds. */
    public const REFUND = 'fees.refund';

    public const REFUND_APPROVE = 'fees.refund.approve';

    public const REFUND_ISSUE = 'fees.refund.issue';

    /** Search due fees / balances. */
    public const DUES_VIEW = 'fees.dues.view';

    /** Send fee reminders from the reminder board. */
    public const REMINDERS_SEND = 'fees.reminders.send';

    /** Carry balances forward between sessions. */
    public const CARRY_FORWARD = 'fees.carry_forward';

    /** Roll the fee structure over into a new session. */
    public const ROLLOVER = 'fees.rollover';

    /** Fee reports. */
    public const REPORTS_VIEW = 'fees.reports.view';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    public static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }
}
