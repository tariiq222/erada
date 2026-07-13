<?php

namespace App\Modules\Meetings\Http\Requests\Concerns;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\DecidableType;
use Illuminate\Validation\Validator;

trait ValidatesRecommendationTarget
{
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $alias = $this->input('decidable_type');
            $id = $this->input('decidable_id');
            if (! is_string($alias) || $alias === '' || ! is_numeric($id)
                || ! in_array($alias, DecidableType::aliases(), true)) {
                return;
            }

            $target = DecidableType::classFor($alias)::query()->withoutGlobalScopes()->find((int) $id);
            $organizationId = $this->recommendationOrganizationId();
            if (! $target instanceof ScopeAware || $target->scopeOrganizationId() !== $organizationId) {
                $validator->errors()->add('decidable_id', 'The selected target is invalid.');
            }
        });
    }

    private function recommendationOrganizationId(): ?int
    {
        $recommendation = $this->route('recommendation');
        if ($recommendation instanceof Recommendation && $recommendation->organization_id !== null) {
            return (int) $recommendation->organization_id;
        }

        return $this->user()?->organization_id === null ? null : (int) $this->user()->organization_id;
    }
}
