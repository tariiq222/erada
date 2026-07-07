<?php

namespace App\Modules\HR\Http\Requests\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Returns the specific Arabic authz-failure messages that the legacy
 * DepartmentController produced before the FormRequest cutover:
 *   - create → ليس لديك صلاحية إنشاء الأقسام
 *   - update → ليس لديك صلاحية تعديل الأقسام
 *   - delete → ليس لديك صلاحية حذف الأقسام
 *
 * Used by StoreDepartmentRequest, UpdateDepartmentRequest, and
 * DeleteDepartmentRequest so the public JSON contract stays identical
 * to the controller-era responses that DepartmentControllerTest pins.
 *
 * Concrete FormRequests opt-in by defining an `$authzAbility` property
 * (one of: create | update | delete).
 */
trait DepartmentAuthzFailureTrait
{
    // ponytail: no default here. Concrete FormRequests declare the typed
    // `$authzAbility` property themselves so PHP's composition rules
    // (trait vs class same-property check) don't reject identical types.

    protected function failedAuthorization(): void
    {
        // Pre-emptive D-03 null-org gate: a non-super user with no
        // organization_id must fail with the precise reason the legacy
        // controller used, not the generic ability message.
        $user = $this->user();
        if ($user !== null && ! $user->isSuperAdmin() && $user->organization_id === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'المستخدم لا ينتمي لمؤسسة',
            ], 403));
        }

        $messages = [
            'create' => 'ليس لديك صلاحية إنشاء الأقسام',
            'update' => 'ليس لديك صلاحية تعديل الأقسام',
            'delete' => 'ليس لديك صلاحية حذف الأقسام',
        ];

        $ability = $this->authzAbility ?? 'create';

        throw new HttpResponseException(response()->json([
            'message' => $messages[$ability] ?? 'ليس لديك صلاحية لهذا الإجراء',
        ], 403));
    }
}
