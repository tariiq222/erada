<?php

namespace App\Modules\Surveys\Support;

use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * SurveyOrgGuard - اشتقاق organization_id وفحص العزل على مستوى المؤسسة لموديول Surveys.
 *
 * هذه الـ Helper هي المرجع الوحيد لاستخراج `organization_id` لأي كيان
 * يخصّ الاستبيانات (Survey, SurveyResponse, SurveyInvitation,
 * DataImportRequest). لا تُكرّر في الـ FormRequests أو الـ Policies أو
 * الكنترولرات. الجداول الفرعية (Response, Invitation, ImportRequest)
 * تعتمد على العلاقة بـ Survey الأب.
 *
 * القواعد الموحّدة (تطابق UserSurveyScope و MeetingOrgGuard):
 *   - super_admin ⇒ مسموح دائماً.
 *   - actor بلا organization_id ⇒ مرفوض (fail-closed).
 *   - targetOrgId = null ⇒ مرفوض (orphaned record).
 *   - mismatch ⇒ مرفوض.
 *
 * Phase 6 — Surveys Org-Isolation: حلّ محل inline checks الموزّعة
 * لاحقاً في Phase 6.B داخل FormRequests والـ Policies.
 */
class SurveyOrgGuard
{
    /**
     * استخراج organization_id من Survey مباشرةً.
     */
    public function surveyOrgId(?Survey $survey): ?int
    {
        if ($survey === null) {
            return null;
        }

        return $survey->organization_id !== null
            ? (int) $survey->organization_id
            : null;
    }

    /**
     * استخراج organization_id من SurveyResponse عبر survey الأب.
     */
    public function responseOrgId(?SurveyResponse $response): ?int
    {
        if ($response === null) {
            return null;
        }

        return $response->survey?->organization_id !== null
            ? (int) $response->survey->organization_id
            : null;
    }

    /**
     * استخراج organization_id من SurveyInvitation عبر survey الأب.
     */
    public function invitationOrgId(?SurveyInvitation $invitation): ?int
    {
        if ($invitation === null) {
            return null;
        }

        return $invitation->survey?->organization_id !== null
            ? (int) $invitation->survey->organization_id
            : null;
    }

    /**
     * استخراج organization_id من DataImportRequest عبر response.survey الجدّ.
     */
    public function importRequestOrgId(?DataImportRequest $request): ?int
    {
        if ($request === null) {
            return null;
        }

        return $request->response?->survey?->organization_id !== null
            ? (int) $request->response->survey->organization_id
            : null;
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
     * فحص Same-Organization لـ Survey target.
     */
    public function sameOrganizationForSurvey(User $actor, ?Survey $survey): bool
    {
        return $this->sameOrganization($actor, $this->surveyOrgId($survey));
    }

    /**
     * فحص Same-Organization لـ SurveyResponse target.
     */
    public function sameOrganizationForResponse(User $actor, ?SurveyResponse $response): bool
    {
        return $this->sameOrganization($actor, $this->responseOrgId($response));
    }

    /**
     * فحص Same-Organization لـ SurveyInvitation target.
     */
    public function sameOrganizationForInvitation(User $actor, ?SurveyInvitation $invitation): bool
    {
        return $this->sameOrganization($actor, $this->invitationOrgId($invitation));
    }

    /**
     * abort مع AccessDeniedHttpException إن لم يكن same-org.
     * للاستخدام في الكنترولر حيث النمط يرمي بدلاً من إرجاع false.
     */
    public function abortUnlessSameOrganization(User $actor, ?int $targetOrgId): void
    {
        if (! $this->sameOrganization($actor, $targetOrgId)) {
            throw new AccessDeniedHttpException('الاستبيان خارج نطاق مؤسستك');
        }
    }
}
