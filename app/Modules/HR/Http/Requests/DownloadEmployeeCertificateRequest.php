<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\EmployeeCertificate;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DownloadEmployeeCertificateRequest - engine-only authz for downloading
 * a certificate file.
 *
 * authorize() runs the HR_VIEW capability gate through the engine, then
 * resolves the certificate's owner organization via employeeProfile->user
 * (the certificate model itself does not carry organization_id). State
 * checks (orphan certificate, file missing) stay in the controller.
 */
class DownloadEmployeeCertificateRequest extends FormRequest
{
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

        $certificate = $this->route('certificate');
        if (! $certificate instanceof EmployeeCertificate) {
            $certificate = EmployeeCertificate::find($certificate);
        }

        // ponytail: null → let route model binding produce the 404.
        if (! $certificate) {
            return true;
        }

        $ownerOrgId = $certificate->employeeProfile?->user?->organization_id;

        if (! $ownerOrgId) {
            throw new NotFoundHttpException('الشهادة غير مرتبطة بموظف');
        }

        if (! $user->isSuperAdmin() && $user->organization_id !== $ownerOrgId) {
            throw new AccessDeniedHttpException('الشهادة خارج نطاق مؤسستك');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
