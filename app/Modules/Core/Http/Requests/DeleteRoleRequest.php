<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteRoleRequest - engine-only authz for deleting a Spatie role.
 *
 * The route group is gated by `role:super_admin` middleware; authorize()
 * mirrors that gate so the structural rule "authorize() belongs in
 * FormRequest" holds. Per-role business rules (isSystemRole, users-exist
 * check) stay in the controller — they are state checks, not AuthZ.
 */
class DeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
