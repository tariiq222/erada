<?php

namespace App\Modules\Core\Authorization\Contracts;

/**
 * Opt-in: a model whose OWNER may edit it only while its lifecycle allows.
 * The engine owner floor grants edit to the owner only when isOwnerEditable() is true.
 * Models without this interface give the owner view-only via the floor (edit still
 * flows through roles).
 */
interface OwnerEditable
{
    public function isOwnerEditable(): bool;
}
