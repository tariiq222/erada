<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 1 Task 1.1.2 -- `authorization_roles` Eloquent model.
 *
 * Named role groupings (admin, project_manager, ...) assigned to users
 * through `authorization_role_assignments`. A role has no direct
 * capability bindings on this model; those live on
 * `authorization_role_permissions`.
 *
 * Phase 2.1.4a adds the per-role `is_admin_role` boolean. It ports the
 * legacy `scoped_role_definitions.is_admin_role` shortcut forward so
 * the unified new-path decision walk
 * (`AccessDecision::hasNewPermission`) can grant every capability
 * through the same assignment/scope gate the rest of the new path uses.
 * The companion migration `2026_07_05_000026_backfill_authorization_roles_is_admin_role`
 * writes the value from the source legacy definition; the engine carve-
 * out that prevents `is_admin_role=true` from silently widening OVR
 * confidential lives in `AccessDecision` (it does NOT live on this
 * column -- the column is just the flag).
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property bool $is_admin_role
 */
class AuthorizationRole extends Model
{
    protected $table = 'authorization_roles';

    protected $fillable = [
        'name',
        'label',
        'is_admin_role',
    ];

    protected $casts = [
        // The is_admin_role column is a NOT NULL boolean on disk; the
        // cast makes `$role->is_admin_role` a real PHP bool on read so
        // the engine's new-path admin gate (`hasNewPermission`) does
        // not have to remember to cast every read.
        'is_admin_role' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Phase 2.1.4a: any write or delete on a role invalidates the
        // admin-role assignment memoization (`$adminAssignmentsCache`)
        // in AccessDecision. The pivot + assignment writes already
        // trigger the same hook on their models; this hook covers the
        // case where an operator flips `is_admin_role` directly via
        // Eloquent without touching assignments.
        static::saved(fn () => AccessDecision::flushCache());
        static::deleted(fn () => AccessDecision::flushCache());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AuthorizationRoleAssignment::class, 'authorization_role_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AuthorizationRolePermission::class, 'authorization_role_id');
    }
}
