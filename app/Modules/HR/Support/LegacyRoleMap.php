<?php

namespace App\Modules\HR\Support;

class LegacyRoleMap
{
    /**
     * Spatie role names that are NEVER migrated onto the scoped model. The
     * legacy sync service already refused to grant/revoke these, so they hold
     * no department-scoped meaning.
     */
    public const PROTECTED = ['admin', 'super_admin'];

    /**
     * Explicit translations from legacy Spatie role names to department-scoped
     * role_keys. Extend this from the verification command's report before
     * running the backfill in production. Unknown non-protected roles default
     * to dept_member (preserves the member-level access legacy granted to all
     * members).
     *
     * @var array<string, string>
     */
    public const MAP = [
        // 'legacy_role_name' => 'scoped_role_key',
    ];

    public static function toScopedKey(string $spatieRoleName): ?string
    {
        if (in_array($spatieRoleName, self::PROTECTED, true)) {
            return null;
        }

        return self::MAP[$spatieRoleName] ?? 'dept_member';
    }
}
