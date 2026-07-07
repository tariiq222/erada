<?php

namespace App\Modules\Core\Authorization\Contracts;

use App\Modules\Core\Models\User;

/**
 * A record whose visibility must NOT leak upward via hierarchy. When isSensitive()
 * is true, only super_admin, the owner floor, or mayAccessSensitive() may grant --
 * ancestor scope-chain and org-functional roles are ignored (need-to-know).
 */
interface SensitivelyScoped
{
    /**
     * Is this specific record currently sensitive (need-to-know)?
     */
    public function isSensitive(): bool;

    /**
     * May the given user access this sensitive record via an explicit
     * need-to-know grant (e.g. reporter/assigned, or a confidential-cleared role)?
     */
    public function mayAccessSensitive(User $user): bool;
}
