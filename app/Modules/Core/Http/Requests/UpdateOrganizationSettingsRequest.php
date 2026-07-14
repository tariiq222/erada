<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }

        if (! AccessDecision::can($actor, Capability::ORGANIZATION_SETTINGS_EDIT)) {
            return false;
        }

        $org = $this->route('organization');
        if ($org === null) {
            return false;
        }

        return $actor->isSuperAdmin() || (int) $actor->organization_id === (int) $org->id;
    }

    public function rules(): array
    {
        return [
            'locale_overrides' => ['sometimes', 'array'],
            'locale_overrides.ar' => ['sometimes', 'nullable', 'string', 'max:16'],
            'locale_overrides.en' => ['sometimes', 'nullable', 'string', 'max:16'],
            'branding_overrides' => ['sometimes', 'array'],
            'branding_overrides.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_overrides.logo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notification_templates' => ['sometimes', 'array'],
            'notification_templates.*' => ['string', 'max:4000'],
        ];
    }
}
