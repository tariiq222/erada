<?php

namespace App\Modules\OVR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'user_id' => $this->user_id,
            'author_name' => $this->author_name,
            'text' => $this->text,
            'is_internal' => $this->is_internal,
            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
