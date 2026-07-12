<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Phase 1 Task 1.1.2 — `authorization_decision_audits` Eloquent model.
 *
 * Append-only audit log of authorization decisions emitted by the
 * engine. Assignment mutations are tracked separately in the canonical
 * `authorization_assignment_audits` store.
 *
 * Append-only: only `created_at` exists, no `updated_at`, and the
 * model does NOT use Eloquent timestamp management — the DB populates
 * `created_at` via `useCurrent()`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $authorization_resource_id
 * @property string $action
 * @property string $decision
 * @property int|null $matched_authorization_role_id
 * @property int|null $matched_authorization_role_assignment_id
 * @property int|null $matched_authorization_record_rule_id
 * @property string $source
 * @property Carbon $created_at
 */
class AuthorizationDecisionAudit extends Model
{
    public const DECISION_ALLOW = 'allow';

    public const DECISION_DENY = 'deny';

    public const SOURCE_ENGINE = 'engine';

    public const SOURCE_SHADOW = 'shadow';

    public const SOURCE_LEGACY = 'legacy';

    protected $table = 'authorization_decision_audits';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'authorization_resource_id',
        'action',
        'decision',
        'matched_authorization_role_id',
        'matched_authorization_role_assignment_id',
        'matched_authorization_record_rule_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: there is no `updated_at` and Eloquent does not manage
        // timestamps, so the DB default `useCurrent()` would normally populate
        // `created_at` only on a round-trip read. We mirror that in-memory so
        // callers see `created_at` immediately after `Model::create([...])`
        // without needing `->refresh()`.
        static::creating(function (self $model): void {
            $model->created_at = $model->created_at ?? now();
        });

        // Append-only: existing rows MUST be immutable. Blocking updates at
        // the Eloquent layer keeps `decision`, `source`, and the matched-*
        // foreign keys from being silently rewritten. We throw a clear
        // LogicException before Eloquent's `performUpdate()` can issue an
        // UPDATE statement, so no DB state is mutated.
        static::updating(function (self $model): void {
            throw new LogicException(
                'AuthorizationDecisionAudit is append-only and cannot be updated; '
                .'create a new audit row instead. (id='.$model->getKey().')'
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(AuthorizationResource::class, 'authorization_resource_id');
    }

    public function matchedRole(): BelongsTo
    {
        return $this->belongsTo(AuthorizationRole::class, 'matched_authorization_role_id');
    }

    public function matchedRoleAssignment(): BelongsTo
    {
        return $this->belongsTo(AuthorizationRoleAssignment::class, 'matched_authorization_role_assignment_id');
    }

    public function matchedRecordRule(): BelongsTo
    {
        return $this->belongsTo(AuthorizationRecordRule::class, 'matched_authorization_record_rule_id');
    }
}
