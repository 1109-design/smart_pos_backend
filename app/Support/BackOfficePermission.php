<?php

namespace App\Support;

/**
 * The customizable-permission catalogue for BackOffice actions. Deliberately
 * separate from the till app's `Permission` Dart enum (see
 * BackOfficeRolePermission / the 2026_09_03_100000 migration for why) — this
 * is its own smaller, BackOffice-specific vocabulary.
 */
final class BackOfficePermission
{
    public const MANAGE_USERS = 'manage_users';

    public const MANAGE_SUPPLIERS = 'manage_suppliers';

    public const MANAGE_CUSTOMERS = 'manage_customers';

    public const MANAGE_PURCHASE_ORDERS = 'manage_purchase_orders';

    public const MANAGE_STOCKTAKES = 'manage_stocktakes';

    public const MANAGE_STOREMAN = 'manage_storeman';

    public const MANAGE_TILLS = 'manage_tills';

    public const ARCHIVE_ALL_PRODUCTS = 'archive_all_products';

    /**
     * Single source of truth for the catalogue — all() and label() both
     * derive from this instead of separately re-listing every permission,
     * which used to mean adding one meant editing three parallel lists.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::MANAGE_USERS => 'Manage users',
        self::MANAGE_SUPPLIERS => 'Manage suppliers',
        self::MANAGE_CUSTOMERS => 'Manage customers',
        self::MANAGE_PURCHASE_ORDERS => 'Manage purchase orders',
        self::MANAGE_STOCKTAKES => 'Approve/reject stocktakes',
        self::MANAGE_STOREMAN => 'Create suggested transfers',
        self::MANAGE_TILLS => 'Move tills between locations',
        self::ARCHIVE_ALL_PRODUCTS => 'Archive all products',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Human label for the roles/permissions management screen.
     */
    public static function label(string $permission): string
    {
        return self::LABELS[$permission] ?? $permission;
    }

    /**
     * Permission set for a role that has never been customized — exactly
     * today's hardcoded behavior (business_owner/manager get everything
     * except owner-only actions; anything else, including a brand new
     * custom role, gets nothing until explicitly granted).
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        return match ($role) {
            'business_owner' => self::all(),
            'manager' => array_values(array_diff(self::all(), [self::ARCHIVE_ALL_PRODUCTS])),
            default => [],
        };
    }
}
