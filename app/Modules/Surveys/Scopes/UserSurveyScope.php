<?php

namespace App\Modules\Surveys\Scopes;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserSurveyScope - الفلتر الموحّد لعزل قوائم الاستبيانات وما يتبعها على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على استعلامات
 * Eloquent الخاصّة بموديول Surveys (surveys, survey_responses,
 * survey_invitations, data_import_requests). لا يُعاد تنفيذه inline في
 * أي Controller أو FormRequest.
 *
 * السلوك (لكل variant):
 *   - super_admin: لا فلتر.
 *   - actor بلا organization_id: whereRaw('1 = 0') — fail-closed (لا يرى شيئاً).
 *   - actor عادي: فلتر organization_id مباشرة على surveys.organization_id
 *     للـ surveys، وعبر whereHas('survey', ...) للجداول الفرعية
 *     (SurveyResponse, SurveyInvitation, DataImportRequest) التي لا تحمل
 *     عمود organization_id مباشر.
 *
 * لا تعتمد على السلسلة الهرمية للأقسام؛ الـ AccessDecision engine يتولّى
 * التفصيل الهرمي عبر scope-chain. هذا الـ Scope مسؤول فقط عن القطع
 * الأفقي لمؤسسة المستخدم (org isolation floor).
 *
 * Phase 6 — Surveys Org-Isolation: تم إنشاؤه كطبقة أساس قبل Phase 6.B
 * التي ستربط الكنترولرات والـ FormRequests بهذا الـ Scope.
 */
class UserSurveyScope
{
    /**
     * فلتر استعلام Survey (الاستبيانات نفسها).
     * العمود organization_id موجود مباشرة في surveys.
     */
    public function applyToSurveys(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('surveys.organization_id', $actor->organization_id);
    }

    /**
     * فلتر استعلام SurveyResponse عبر survey الأب.
     * survey_responses لا يحمل organization_id مباشرة، فالاشتقاق يتمّ
     * عبر العلاقة.
     */
    public function applyToSurveyResponses(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }

    /**
     * فلتر استعلام SurveyInvitation عبر survey الأب.
     * survey_invitations لا يحمل organization_id مباشرة، فالاشتقاق
     * يتمّ عبر العلاقة.
     */
    public function applyToSurveyInvitations(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }

    /**
     * فلتر استعلام DataImportRequest عبر response.survey الجدّ.
     * data_import_requests لا يحمل organization_id ولا survey_id مباشرة،
     * فالاشتقاق يتمّ عبر response -> survey (علاقتان متتاليتان).
     */
    public function applyToDataImportRequests(Builder $query, User $actor): Builder
    {
        return $query->whereHas(
            'response.survey',
            fn (Builder $s) => $this->applyToSurveys($s, $actor)
        );
    }
}
