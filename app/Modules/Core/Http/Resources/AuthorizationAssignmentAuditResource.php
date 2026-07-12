<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorizationAssignmentAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->event,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'role' => $this->role,
            'reason' => $this->reason,
            'old_values' => $this->old_value,
            'new_values' => $this->new_value,
            'user' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
