<?php

namespace App\Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 1 Task 1.1.2 — `authorization_resources` Eloquent model.
 *
 * Canonical resource catalog. The `key` column stores a model FQCN
 * (for example `App\Modules\Projects\Models\Project`) and is unique
 * so seeded lookup is a single index hit.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 */
class AuthorizationResource extends Model
{
    protected $table = 'authorization_resources';

    protected $fillable = [
        'key',
        'label',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(AuthorizationRolePermission::class, 'authorization_resource_id');
    }

    public function recordRules(): HasMany
    {
        return $this->hasMany(AuthorizationRecordRule::class, 'authorization_resource_id');
    }

    public function decisionAudits(): HasMany
    {
        return $this->hasMany(AuthorizationDecisionAudit::class, 'authorization_resource_id');
    }
}
