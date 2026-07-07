<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\EmployeeCertificate;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteEmployeeCertificateRequest extends FormRequest
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

        $certificate = $this->route('certificate');
        if (! $certificate instanceof EmployeeCertificate) {
            $certificate = EmployeeCertificate::find($certificate);
        }
        if (! $certificate) {
            return false;
        }

        $ownerOrgId = $certificate->employeeProfile?->user?->organization_id;

        if (! $ownerOrgId) {
            throw new NotFoundHttpException('الشهادة غير مرتبطة بموظف');
        }

        if (! $user->isSuperAdmin() && $user->organization_id !== $ownerOrgId) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
