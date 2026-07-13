<?php

namespace App\Modules\Meetings\Http\Requests\Concerns;

use App\Modules\Meetings\Models\ResolutionLink;
use Illuminate\Validation\Validator;

trait ValidatesResolutionLinks
{
    protected function validateResolutionLinks(Validator $validator, ?int $organizationId): void
    {
        foreach ((array) $this->input('links', []) as $index => $link) {
            if (! is_array($link)) {
                continue;
            }

            $linkableClass = ResolutionLink::resolveClass((string) ($link['linkable_type'] ?? ''));
            $linkableId = filter_var($link['linkable_id'] ?? null, FILTER_VALIDATE_INT);

            if ($linkableClass === null || $linkableId === false || $linkableId < 1) {
                continue;
            }

            $belongsToResolutionOrganization = $linkableClass::query()
                ->whereKey($linkableId)
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $belongsToResolutionOrganization) {
                $validator->errors()->add(
                    "links.{$index}.linkable_id",
                    'العنصر المرتبط غير موجود أو لا ينتمي إلى مؤسسة الاجتماع.',
                );
            }
        }
    }
}
