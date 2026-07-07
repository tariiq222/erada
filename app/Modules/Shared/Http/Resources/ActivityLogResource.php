<?php

namespace App\Modules\Shared\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    private const REDACTED = '[REDACTED]';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->action_label,
            'action_color' => $this->action_color,
            'description' => $this->description,
            'model_label' => $this->model_label,
            'loggable_type' => $this->loggable_type ? class_basename($this->loggable_type) : null,
            'loggable_id' => $this->loggable_id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'role' => $this->role,
            'reason' => $this->reason,
            'old_values' => $this->redact($this->old_values),
            'new_values' => $this->redact($this->new_values),
            'metadata' => $this->redact($this->metadata),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redact($item) : $item;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/^(is_confidential|severity_level|status)$|'.
            'token|password|secret|email|authorization|cookie|header|ip[_-]?address|user[_-]?agent|'.
            '^patient[_-]|^reporter[_-]?email$|^reporter[_-]?(name|extension|job_title|department_id|section_id)$|'.
            '^(incident_description|actions_taken|closure_reason|reopen_reason)$/i',
            $key
        ) === 1;
    }
}
