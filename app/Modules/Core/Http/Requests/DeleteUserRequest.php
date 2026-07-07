<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteUserRequest - engine-only authz for deleting a user.
 *
 * authorize() runs the existing UserPolicy::delete path through the unified
 * AuthZ engine (self-block + capability gate + super-admin target block +
 * org-isolation floor). No payload rules — delete accepts an empty body.
 */
class DeleteUserRequest extends FormRequest
{
    protected ?User $user = null;

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

        $this->user = $user;

        return $this->user()?->can('delete', $user) ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Helper for the controller — the route-bound target user that survived
     * authorize(). Named `targetUser()` to avoid colliding with the parent
     * Symfony\Request::getUser(): ?string (HTTP basic-auth username), which
     * is an LSP-fatal mismatch in PHP 8.x.
     */
    public function targetUser(): ?User
    {
        return $this->user;
    }
}
