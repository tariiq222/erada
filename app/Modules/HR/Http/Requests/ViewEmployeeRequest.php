<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * ViewEmployeeRequest - engine-only authz for reading a single employee.
 *
 * authorize() runs the HR_VIEW capability gate through the engine, then
 * enforces the same-organization floor (User is not ScopeAware so the
 * engine cannot derive org id from the target). Throws 403 on
 * cross-organization access.
 */
class ViewEmployeeRequest extends FormRequest
{
    protected ?User $employee = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::HR_VIEW)) {
            return false;
        }

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof User) {
            $employee = User::find($employee);
        }

        // ponytail: return true on null so route model binding's natural 404
        // runs (e.g. /api/hr/employees/999999).
        if (! $employee) {
            return true;
        }

        $this->employee = $employee;

        if (! $user->isSuperAdmin() && $employee->organization_id !== $user->organization_id) {
            throw new AccessDeniedHttpException('الموظف خارج نطاق مؤسستك');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function getEmployee(): ?User
    {
        return $this->employee;
    }
}
