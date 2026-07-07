<?php

namespace App\Modules\Meetings\Scopes;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserMeetingScope - الفلتر الموحّد لعزل قوائم الاجتماعات وما يتبعها على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على استعلامات
 * Eloquent الخاصّة بموديول Meetings (ما عدا Recommendation، التي
 * يملك scopeVisibleTo() الخاص بها). لا يُعاد تنفيذه inline في أي Controller.
 *
 * السلوك (لكل variant):
 *   - super_admin: لا فلتر.
 *   - actor بلا organization_id: whereRaw('false') — fail-closed (لا يرى شيئاً).
 *   - actor عادي: فلتر organization_id مباشرة على عمود الجدول، أو عبر
 *     علاقة meeting الأب للأجندات والحضور (pivot).
 *
 * لا تعتمد على السلسلة الهرمية للأقسام؛ الـ AccessDecision engine يتولّى
 * التفصيل الهرمي عبر scope-chain. هذا الـ Scope مسؤول فقط عن القطع
 * الأفقي لمؤسسة المستخدم (org isolation floor).
 *
 * Phase 5.A — Meetings Org-Isolation: تم إنشاؤه كطبقة أساس قبل Phase 5.B
 * التي ستربط الكنترولرات والـ FormRequests بهذا الـ Scope.
 */
class UserMeetingScope
{
    /**
     * فلتر استعلام Meeting (الاجتماعات نفسها).
     */
    public function applyToMeetings(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meetings.organization_id', $actor->organization_id);
    }

    /**
     * فلتر استعلام MeetingAgendaItem عبر meeting الأب.
     * العمود organization_id موجود في meeting_agenda_items مباشرة،
     * لكن نمرّ عبر العلاقة لأبقى متّسقاً مع المرجع الأعلى (meeting).
     */
    public function applyToAgendaItems(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'meeting',
            fn (Builder $m) => $this->applyToMeetings($m, $actor)
        );
    }

    /**
     * فلتر استعلام MeetingAttendee (pivot) عبر meeting الأب.
     * pivot لا يحمل organization_id مباشرة، فالاشتقاق يتمّ عبر العلاقة.
     */
    public function applyToAttendees(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'meeting',
            fn (Builder $m) => $this->applyToMeetings($m, $actor)
        );
    }

    /**
     * فلتر استعلام MeetingCategory.
     */
    public function applyToCategories(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meeting_categories.organization_id', $actor->organization_id);
    }

    /**
     * فلتر استعلام MeetingSettings (صف واحد لكل org عادةً).
     */
    public function applyToSettings(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meeting_settings.organization_id', $actor->organization_id);
    }
}
