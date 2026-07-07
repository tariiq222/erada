<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeCertificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! AccessDecision::can($user, Capability::HR_MANAGE)) {
            return false;
        }

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof User) {
            $employee = User::find($employee);
        }
        if (! $employee) {
            return false;
        }

        if (! $user->isSuperAdmin() && $employee->organization_id !== $user->organization_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EmployeeCertificate::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
