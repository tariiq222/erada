<?php

namespace App\Modules\Core\Models;

/**
 * Migration-only compatibility shim.
 *
 * The runtime model was removed by the canonical authorization cutover. Two
 * historical migrations still call its cache invalidator while replaying
 * legacy rows; keeping this no-op shim outside app/ prevents that old model
 * from re-entering the application runtime.
 */
final class ScopedRoleDefinition
{
    public static function clearCache(): void
    {
        // Legacy model cache no longer exists in the canonical runtime.
    }
}
