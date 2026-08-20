<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * V1 roles. Roles are only ever collections of permissions -- application code
 * authorizes against permissions (see PermissionName), never against these.
 *
 * The one legitimate exception is the "last active Admin" business protection,
 * which is inherently a statement about the Admin role itself.
 */
enum RoleName: string
{
    case Admin = 'admin';
    case Manager = 'manager';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Default permission set for this role.
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => PermissionName::adminDefaults(),
            self::Manager => PermissionName::managerDefaults(),
        };
    }
}
