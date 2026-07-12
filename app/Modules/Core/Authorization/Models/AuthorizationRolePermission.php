<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Phase 1 Task 1.1.2 — `authorization_role_permissions` Eloquent pivot.
 *
 * Pure pivot linking a role to (resource, action). The composite primary
 * key `(authorization_role_id, authorization_resource_id, action)` IS
 * the row identity — there is no surrogate `id` column and no timestamp
 * columns. The model is read-only at the Eloquent layer.
 *
 * Extends `Illuminate\Database\Eloquent\Relations\Pivot` so the model
 * participates in Eloquent's pivot machinery (sync, attach, detach,
 * `via()` queries, etc.). `Pivot` already defaults to `$incrementing=false`
 * and treats itself as a pivot table; we keep those defaults explicit and
 * disable Eloquent timestamp management because the table has neither
 * `created_at` nor `updated_at` columns.
 *
 * The nullable `reach` JSON column carries a per-module reach cap
 * (`{projects: own|department|all}`). A NULL value means no additional
 * cap on this row. Reach only ever restricts access; it never widens it.
 *
 * @property int $authorization_role_id
 * @property int $authorization_resource_id
 * @property string $action
 * @property array<string, string>|null $reach
 */
class AuthorizationRolePermission extends Pivot
{
    protected $table = 'authorization_role_permissions';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'authorization_role_id',
        'authorization_resource_id',
        'action',
        'reach',
    ];

    protected $casts = [
        // The reach JSON column is a per-module map; the cast makes
        // `$pivot->reach` return a decoded array on read, which the
        // engine's new-path reach check pattern-matches on. A NULL
        // value round-trips as PHP null (no fake default).
        'reach' => 'array',
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

    public function resource(): BelongsTo
    {
        return $this->belongsTo(AuthorizationResource::class, 'authorization_resource_id');
    }
}
