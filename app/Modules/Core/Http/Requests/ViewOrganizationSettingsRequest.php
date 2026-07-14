<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

final class ViewOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }

        if (! AccessDecision::can($actor, Capability::ORGANIZATION_SETTINGS_VIEW)) {
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
        return [];
    }
}
