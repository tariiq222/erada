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
 * `is_admin_role` grants capabilities through the same assignment and
 * scope checks used for every role. The OVR confidential carve-out lives
 * in `AccessDecision`; this column only stores the role flag.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property bool $is_admin_role
 * @property bool $is_active
 */
class AuthorizationRole extends Model
{
    protected $table = 'authorization_roles';

    protected $fillable = [
        'name',
        'label',
        'label_ar',
        'label_en',
        'scope_type',
        'is_admin_role',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        // The is_admin_role column is a NOT NULL boolean on disk; the
        // cast makes `$role->is_admin_role` a real PHP bool on read so
        // the engine's new-path admin gate (`hasNewPermission`) does
        // not have to remember to cast every read.
        'is_admin_role' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
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
