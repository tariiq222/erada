<?php

namespace App\Modules\Core\Authorization\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizationAssignmentAudit extends Model
{
    protected $table = 'authorization_assignment_audits';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        $organizationId = $actor->organization_id;
        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($organizationId): void {
            $visible->whereHas('targetUser', fn (Builder $target): Builder => $target->where('organization_id', $organizationId))
                ->orWhere(function (Builder $systemEvent) use ($organizationId): void {
                    $systemEvent->whereNull('target_user_id')
                        ->where(function (Builder $owned) use ($organizationId): void {
                            $owned->whereHas('actor', fn (Builder $actor): Builder => $actor->where('organization_id', $organizationId))
                                ->orWhere(fn (Builder $scope): Builder => $scope
                                    ->where('scope_type', 'organization')
                                    ->where('scope_id', $organizationId));
                        });
                });
        });
    }
}
