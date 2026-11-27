<?php

namespace Classivo\ReportCards\Support;

/**
 * Permission names (spatie/laravel-permission style). Deny by default: nothing is
 * allowed unless an authenticated user holds the permission (an unseeded permission
 * denies everyone).
 */
final class Permissions
{
    public const RESOURCES = [
        'report_card_templates', 'certificate_templates', 'id_card_templates',
        'generated_certificates', 'transfer_certificates',
    ];

    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    public const EXTRA = [
        'generated_certificates.issue',
        'generated_certificates.download',
        'transfer_certificates.download',
        'marksheets.download',
    ];

    /** @return list<string> every permission name, for seeding roles. */
    public static function all(): array
    {
        $names = [];
        foreach (self::RESOURCES as $r) {
            foreach (self::ACTIONS as $a) {
                $names[] = "{$r}.{$a}";
            }
        }

        return [...$names, ...self::EXTRA];
    }

    public static function allows(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && (bool) $user->can($permission);
    }
}
