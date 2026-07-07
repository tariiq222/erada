<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewUserRequest - engine-only authz for reading a single user.
 *
 * authorize() runs the existing UserPolicy::view path through the unified
 * AuthZ engine (self floor + capability gate + org-isolation floor). No
 * payload rules — read endpoints take no user input.
 */
class ViewUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $user instanceof User) {
            $user = User::find($user);
        }

        // ponytail: return true on null so route model binding's natural 404
        // runs (e.g. /api/users/999999). Returning false here would yield a
        // misleading 403 instead of the 404 the HTTP semantics demand.
        if (! $user) {
            return true;
        }

        return $this->user()?->can('view', $user) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
