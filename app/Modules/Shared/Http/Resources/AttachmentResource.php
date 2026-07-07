<?php

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
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
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'formatted_size' => $this->formatted_size,
            'download_url' => url("/api/attachments/{$this->id}/download"),

            // العلاقات
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),

            // التواريخ
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
