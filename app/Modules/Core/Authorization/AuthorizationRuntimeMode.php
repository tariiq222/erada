<?php

namespace App\Modules\Core\Authorization;

/**
 * AuthorizationRuntimeMode -- single source of truth for runtime-mode toggles.
 *
 * Phase 1 Task 1.1.4 -- no config file, no artisan command. The shadow branch
 * is enabled per test or staging by `enableShadow()`, otherwise the engine
 * runs the unmodified legacy path. Only `AccessDecision` itself reads
 * `isShadow()` -- no production controller, policy, or frontend code branches
 * on runtime mode (per the plan's hard constraint).
 *
 * `reset()` and `flush()` both clear back to the disabled default so each
 * test starts from a known state regardless of the order PHPUnit runs them in.
 */
final class AuthorizationRuntimeMode
{
    private static bool $shadow = false;

    public static function enableShadow(): void
    {
        self::$shadow = true;
    }

    public static function disableShadow(): void
    {
        self::$shadow = false;
    }

    public static function isShadow(): bool
    {
        return self::$shadow;
    }

    public static function flush(): void
    {
        self::$shadow = false;
    }

    public static function reset(): void
    {
        self::$shadow = false;
    }
}
