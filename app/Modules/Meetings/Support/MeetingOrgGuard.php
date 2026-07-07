<?php

namespace App\Modules\Meetings\Support;

use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Models\Recommendation;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * MeetingOrgGuard - اشتقاق organization_id وفحص العزل على مستوى المؤسسة لموديول Meetings.
 *
 * هذه الـ Helper هي المرجع الوحيد لاستخراج `organization_id` لأي كيان
 * يخصّ الاجتماعات والتوصيات. لا تُكرّر في الـ FormRequests أو الـ Policies أو
 * الكنترولرات. الـ Recommendation يعتمد على العمود المباشر أو يلجأ إلى
 * الـ meeting الأب — مطابق لمنطق Recommendation::scopeOrganizationId().
 *
 * القواعد الموحّدة (تطابق UserMeetingScope و EmployeeOrgGuard):
 *   - super_admin ⇒ مسموح دائماً.
 *   - actor بلا organization_id ⇒ مرفوض (fail-closed).
 *   - targetOrgId = null ⇒ مرفوض (orphaned record).
 *   - mismatch ⇒ مرفوض.
 *
 * Phase 5.A — Meetings Org-Isolation: حلّ محل inline checks
 * (e.g. (int) $user->organization_id !== (int) $meeting->organization_id)
 * الموزّعة لاحقاً في Phase 5.B داخل 4 FormRequests على الأقل.
 */
class MeetingOrgGuard
{
    /**
     * استخراج organization_id من Meeting مباشرةً.
     */
    public function meetingOrgId(?Meeting $meeting): ?int
    {
        if ($meeting === null) {
            return null;
        }

        return $meeting->organization_id !== null
            ? (int) $meeting->organization_id
            : null;
    }

    /**
     * استخراج organization_id من Recommendation.
     * يطابق منطق Recommendation::scopeOrganizationId():
     *   1) العمود المباشر recommendations.organization_id إن وُجد.
     *   2) وإلا فالـ meeting الأب recommendations.meeting.organization_id.
     *   3) وإلا فـ null.
     */
    public function recommendationOrgId(?Recommendation $rec): ?int
    {
        if ($rec === null) {
            return null;
        }

        if ($rec->organization_id) {
            return (int) $rec->organization_id;
        }

        return $rec->meeting?->organization_id
            ? (int) $rec->meeting->organization_id
            : null;
    }

    /**
     * استخراج organization_id من MeetingAgendaItem عبر meeting الأب.
     */
    public function agendaItemOrgId(?MeetingAgendaItem $item): ?int
    {
        if ($item === null) {
            return null;
        }

        return $item->meeting?->organization_id
            ? (int) $item->meeting->organization_id
            : null;
    }

    /**
     * استخراج organization_id للمستخدم المعنيّ في عملية ربط حضور
     * (MeetingAttendee). يُستخدم من MeetingAttendeeController::update/detach
     * للتحقّق من أنّ {user} في route-bound ينتمي لنفس مؤسسة الـ meeting.
     *
     * يعود null إذا كان $meeting أو الـ user بلا organization_id، أو إذا
     * لم يُعثر على المستخدم.
     */
    public function attendeeUserOrgId(?Meeting $meeting, int $userId): ?int
    {
        if ($meeting === null) {
            return null;
        }

        $attendee = User::find($userId);

        if ($attendee === null) {
            return null;
        }

        return $attendee->organization_id !== null
            ? (int) $attendee->organization_id
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
     * فحص Same-Organization لـ Meeting target.
     */
    public function sameOrganizationForMeeting(User $actor, ?Meeting $meeting): bool
    {
        return $this->sameOrganization($actor, $this->meetingOrgId($meeting));
    }

    /**
     * فحص Same-Organization لـ Recommendation target.
     */
    public function sameOrganizationForRecommendation(User $actor, ?Recommendation $rec): bool
    {
        return $this->sameOrganization($actor, $this->recommendationOrgId($rec));
    }

    /**
     * فحص Same-Organization لـ MeetingAgendaItem target.
     */
    public function sameOrganizationForAgendaItem(User $actor, ?MeetingAgendaItem $item): bool
    {
        return $this->sameOrganization($actor, $this->agendaItemOrgId($item));
    }

    /**
     * abort مع AccessDeniedHttpException إن لم يكن same-org.
     * للاستخدام في الكنترولر حيث النمط يرمي بدلاً من إرجاع false.
     */
    public function abortUnlessSameOrganization(User $actor, ?int $targetOrgId): void
    {
        if (! $this->sameOrganization($actor, $targetOrgId)) {
            throw new AccessDeniedHttpException('الاجتماع خارج نطاق مؤسستك');
        }
    }
}
