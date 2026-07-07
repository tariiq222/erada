<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

class AssignableRoleKey implements ValidationRule
{
    /**
     * @var array<int, string>
     */
    private const COMPAT_SPATIE_ROLES = ['super_admin', 'admin', 'viewer'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('الدور المحدد غير متاح.');

            return;
        }

        $roleKey = trim($value);

        $existsAsOrgDefinition = ScopedRoleDefinition::query()
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('role_key', $roleKey)
            ->where('is_active', true)
            ->exists();

        if ($existsAsOrgDefinition) {
            return;
        }

        $existsAsCompatRole = in_array($roleKey, self::COMPAT_SPATIE_ROLES, true)
            && Role::where('name', $roleKey)->where('guard_name', 'web')->exists();

        if ($existsAsCompatRole) {
            return;
        }

        $fail('الدور المحدد غير متاح.');
    }
}
