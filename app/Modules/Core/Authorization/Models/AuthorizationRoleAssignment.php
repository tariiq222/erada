<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1 Task 1.1.2 — `authorization_role_assignments` Eloquent model.
 *
 * Pivot linking a user to a role under a scope. Scope discriminates
 * WHERE the role applies (all / organization / cluster / hospital /
 * department / team / own); the schema enforces that `scope_id` is
 * NULL only when `scope_type` is `all` or `own`.
 *
 * `organization_id` is a denormalized convenience that equals
 * `scope_id` when `scope_type = 'organization'` and is NULL otherwise.
 *
 * @property int $id
 * @property int $authorization_role_id
 * @property int $user_id
 * @property string $scope_type
 * @property int|null $scope_id
 * @property int|null $organization_id
 * @property bool $inherit_to_children
 */
class AuthorizationRoleAssignment extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_CLUSTER = 'cluster';

    public const SCOPE_HOSPITAL = 'hospital';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_OWN = 'own';

    protected $table = 'authorization_role_assignments';

    protected $fillable = [
        'authorization_role_id',
        'user_id',
        'scope_type',
        'scope_id',
        'organization_id',
        'inherit_to_children',
    ];

    protected $casts = [
        'inherit_to_children' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => AccessDecision::flushCache());
        static::deleted(fn () => AccessDecision::flushCache());
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AuthorizationRole::class, 'authorization_role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
