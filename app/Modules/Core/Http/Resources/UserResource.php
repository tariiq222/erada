<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Email is PII — restrict to self, admin, or view_users perm.
            'email' => $this->when(
                $request->user()?->id === $this->id
                    || $request->user()?->isAdmin()
                    || $request->user()?->isSuperAdmin()
                    || $request->user()?->can('view_users'),
                fn () => $this->email
            ),
            'job_title' => $this->job_title,
            'avatar' => $this->avatar,
            'is_active' => $this->is_active,

            // العلاقات
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            // التواريخ
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
