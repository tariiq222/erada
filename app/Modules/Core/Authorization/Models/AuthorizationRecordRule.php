<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1 Task 1.1.2 — `authorization_record_rules` Eloquent model.
 *
 * Structured per-record authorization rules. Each row narrows a
 * (resource, action) capability down to records whose columns satisfy
 * the structured `domain_json` payload.
 *
 * Scoping (NULL role + NULL user) means "applies to everyone who
 * reaches this resource"; setting either column narrows the audience.
 *
 * @property int $id
 * @property int|null $authorization_role_id
 * @property int|null $user_id
 * @property int $authorization_resource_id
 * @property string|null $action
 * @property array<string, mixed> $domain_json
 * @property int $priority
 * @property bool $enabled
 */
class AuthorizationRecordRule extends Model
{
    protected $table = 'authorization_record_rules';

    protected $fillable = [
        'authorization_role_id',
        'user_id',
        'authorization_resource_id',
        'action',
        'domain_json',
        'priority',
        'enabled',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => AccessDecision::flushCache());
        static::deleted(fn () => AccessDecision::flushCache());
    }

    protected function casts(): array
    {
        return [
            'domain_json' => 'array',
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AuthorizationRole::class, 'authorization_role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(AuthorizationResource::class, 'authorization_resource_id');
    }

    /**
     * Limit the query to enabled rules only.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Limit the query to rules whose resource key matches the given FQCN.
     * Joins through `authorization_resources` because rules reference it by id.
     */
    public function scopeForResource(Builder $query, string $resourceKey): Builder
    {
        return $query->whereHas('resource', fn (Builder $q) => $q->where('key', $resourceKey));
    }

    /**
     * Limit the query to rules whose action matches the given action.
     * A NULL action on either side means "applies to every action" — those
     * rows are included alongside the exact match.
     */
    public function scopeForAction(Builder $query, ?string $action): Builder
    {
        return $query->where(function (Builder $q) use ($action) {
            $q->whereNull('action')->orWhere('action', $action);
        });
    }

    /**
     * Limit the query to rules that target the given role, the given user,
     * OR the global wildcard (NULL role and NULL user). Passing NULL for
     * both arguments matches only the wildcard rows.
     */
    public function scopeForRoleOrUser(Builder $query, ?int $roleId, ?int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($roleId, $userId) {
            $q->where(function (Builder $inner) {
                $inner->whereNull('authorization_role_id')->whereNull('user_id');
            });

            if ($roleId !== null) {
                $q->orWhere('authorization_role_id', $roleId);
            }

            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
            }
        });
    }
}
