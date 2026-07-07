<?php

namespace App\Modules\HR\Support;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * EmployeeOrgGuard - اشتقاق organization_id لفحص العزل على مستوى المؤسسة.
 *
 * هذه الـ Helper هي المرجع الوحيد لاستخراج `organization_id` لأي كيان HR
 * يخصّ الموظفين. لا تُكرّر في الـ FormRequests أو الـ Policies. كل
 * الكيانات الفرعية (Profile/Certificate/PersonalInfo) ليس لها عمود
 * organization_id مباشر، فالاشتقاق يمر عبر سلسلة العلاقات
 * (user → employeeProfile → certificate/personalInfo).
 *
 * القواعد الموحّدة (تطابق UserEmployeeScope):
 *   - super_admin ⇒ مسموح دائماً.
 *   - actor بلا organization_id ⇒ مرفوض (fail-closed).
 *   - targetOrgId = null ⇒ مرفوض (orphaned record).
 *   - mismatch ⇒ مرفوض.
 *
 * تم تصميمه ليتوسّع لاحقًا إذا فُصل Employee عن User Account:
 * حاليًا employeeOrgId() يقرأ $employee->organization_id مباشرة
 * (User هو الجدول الذي يحمل العمود). بعد فصل Employee يصبح
 * عبر employee.account.organization_id أو employee.organization_id
 * حسب التصميم النهائي — هذه الـ Helper هي نقطة التوسعة الوحيدة.
 */
class EmployeeOrgGuard
{
    /**
     * استخراج organization_id من User (الموظف).
     */
    public function employeeOrgId(?User $employee): ?int
    {
        if ($employee === null) {
            return null;
        }

        return $employee->organization_id !== null
            ? (int) $employee->organization_id
            : null;
    }

    /**
     * استخراج organization_id من EmployeeProfile عبر user relation.
     * profile.user.organization_id.
     */
    public function profileOrgId(?EmployeeProfile $profile): ?int
    {
        if ($profile === null) {
            return null;
        }

        return $this->employeeOrgId($profile->user);
    }

    /**
     * استخراج organization_id من EmployeeCertificate عبر
     * employeeProfile.user.organization_id.
     */
    public function certificateOrgId(?EmployeeCertificate $certificate): ?int
    {
        if ($certificate === null) {
            return null;
        }

        return $this->profileOrgId($certificate->employeeProfile);
    }

    /**
     * استخراج organization_id من EmployeePersonalInfo عبر
     * employeeProfile.user.organization_id.
     */
    public function personalInfoOrgId(?EmployeePersonalInfo $info): ?int
    {
        if ($info === null) {
            return null;
        }

        return $this->profileOrgId($info->employeeProfile);
    }

    /**
     * فحص Same-Organization بين actor و targetOrgId.
     *
     * - super_admin ⇒ true دائماً.
     * - actor بلا organization_id ⇒ false (fail-closed).
     * - targetOrgId null ⇒ false.
     * - mismatch ⇒ false.
     * - match ⇒ true.
     */
    public function sameOrganization(User $actor, ?int $targetOrgId): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($actor->organization_id === null) {
            return false;
        }

        if ($targetOrgId === null) {
            return false;
        }

        return (int) $actor->organization_id === (int) $targetOrgId;
    }

    /**
     * فحص Same-Organization لـ User target (الموظف).
     */
    public function sameOrganizationForUser(User $actor, ?User $target): bool
    {
        return $this->sameOrganization($actor, $this->employeeOrgId($target));
    }

    /**
     * فحص Same-Organization لـ EmployeeProfile target.
     */
    public function sameOrganizationForProfile(User $actor, ?EmployeeProfile $target): bool
    {
        return $this->sameOrganization($actor, $this->profileOrgId($target));
    }

    /**
     * فحص Same-Organization لـ EmployeeCertificate target.
     */
    public function sameOrganizationForCertificate(User $actor, ?EmployeeCertificate $target): bool
    {
        return $this->sameOrganization($actor, $this->certificateOrgId($target));
    }

    /**
     * فحص Same-Organization لـ EmployeePersonalInfo target.
     */
    public function sameOrganizationForPersonalInfo(User $actor, ?EmployeePersonalInfo $target): bool
    {
        return $this->sameOrganization($actor, $this->personalInfoOrgId($target));
    }

    /**
     * abort مع AccessDeniedHttpException إن لم يكن same-org.
     * للاستخدام في FormRequests::authorize() حيث نمط النظام يرجّع false
     * (وليس throw)، هذه الـ method للاستخدام في الكنترولر فقط.
     */
    public function abortUnlessSameOrganization(User $actor, ?int $targetOrgId): void
    {
        if (! $this->sameOrganization($actor, $targetOrgId)) {
            throw new AccessDeniedHttpException('الموظف خارج نطاق مؤسستك');
        }
    }
}
