<?php

namespace App\Modules\Projects\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserProjectScope - تطبيق فلتر صلاحيات المستخدم على استعلامات المشاريع
 *
 * نموذج الرؤية إضافي (OR) لا بوّابة صلبة: المستخدم (غير super_admin) يرى مشروعاً
 * إذا تحقّق أيٌّ مما يلي بعد فرض عزل المؤسسة:
 *   - ارتباط مباشر: منشئ / عضو / دور سياقي / صاحب مصلحة / مكلّف بمهمة (دائماً).
 *   - منحة محرّك AccessDecision: أدوار سياقية على القسم (مع شجرته الهابطة) أو على
 *     المشروع، أو دور وظيفي على مستوى المؤسسة (يمنح كامل المؤسسة).
 *   - توسعة السلّم المسطّح: view_projects → كامل المؤسسة؛ view_department_projects
 *     → قسمه وشجرته؛ view_own_projects → الارتباطات المباشرة فقط. الصلاحية المسطّحة
 *     تزيد المدى ولا تُعدّ شرطاً مسبقاً لرؤية ما يرتبط به المستخدم مباشرة.
 *   - بلا أي ارتباط ولا منحة ولا صلاحية → لا شيء.
 *
 * super_admin يرى الكل. عزل المؤسسة يُطبَّق أولاً دائماً فيمنع التسرب عبر
 * المؤسسات أياً كان مستوى الوصول. هذه هي المرجعية الوحيدة لقرار رؤية القوائم
 * (DashboardController / ProjectController / ProjectQueryService) كي لا يختلف
 * نطاق لوحة المعلومات عن نطاق قائمة المشاريع لنفس المستخدم.
 */
class UserProjectScope
{
    /**
     * تطبيق فلتر الصلاحيات على استعلام المشاريع
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // عزل المؤسسة أولاً (يمنع التسرب عبر المؤسسات).
        if ($user->organization_id) {
            $query->where('organization_id', $user->organization_id);
        }

        // سقف الرؤية المسطّح هو المرجع حين توجد صلاحية مسطّحة: من يملك صلاحية على
        // السلّم (own/department/all) تحدّد صلاحيته سقفَ مداه — لا يوسّعه الجسر
        // الوظيفي على مستوى المؤسسة (الذي قد يحمل can_view_all مخلَّفاً من ترحيل
        // أدوار قديمة) فوق ذلك السقف. الجسر يعمل فقط حين لا صلاحية مسطّحة إطلاقاً.
        // ponytail: السلّم المسطّح القديم يترجم إلى منح المحرّك — own=project scopes،
        // department=dept scopes، all=org-level grant. منح المحرّك أوسع من السلّم
        // القديم لأن grant على القسم يشمل الشجرة الهابطة؛ السلوك الجديد متعمَّد.
        // grantsAtOrganization يقرأ Spatie roles فقط؛ grantingScopes['organization']
        // يقرأ ScopedRoles — كلاهما يفعّل hasFlatAll (يدعم grant عبر assignScopedRole
        // في الاختبارات ودور admin في الإنتاج).
        $engineGrantsOrg = AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW);
        $engineScopes = AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW);
        $hasFlatAll = $engineGrantsOrg || ! empty($engineScopes['organization'] ?? []);
        $hasFlatDept = ! empty($engineScopes['department'] ?? []);
        $hasFlatOwn = ! empty($engineScopes['project'] ?? []);
        $hasAnyFlat = $hasFlatAll || $hasFlatDept || $hasFlatOwn;

        // وصول كامل المؤسسة: view_projects المسطّحة، أو — حين لا صلاحية مسطّحة —
        // دور وظيفي على مستوى المؤسسة يمنح projects.view. العزل أعلاه يبقى محكماً.
        if ($hasFlatAll
            || (! $hasAnyFlat && $engineGrantsOrg)) {
            return $query;
        }

        // رؤية القسم المُشرِّع على النوع: عضو القسم المُشرِّع يرى كل مشاريع نوعه في
        // المؤسسة. ومكتب إدارة المشاريع (المُشرِّع على النوع 'development') يشرف على
        // المحفظة كاملةً فيرى كل المشاريع بكل أنواعها.
        $governedTypes = app(ProjectAuthorizationService::class)->governedTypes($user);
        if (in_array('development', $governedTypes, true)) {
            return $query;
        }

        // وإلا: نطاق إضافي (OR) = ارتباط مباشر + منح المحرّك (قسم/مشروع) + توسعة
        // السلّم المسطّح على مستوى القسم + نوع يُشرِف عليه. أي واحد منها يكفي للرؤية.
        $deptIds = [];

        // توسعة view_department_projects: قسم المستخدم وشجرته الهابطة.
        if ($hasFlatDept && $user->department_id) {
            $deptIds = AccessDecision::subtreeDepartmentIds([$user->department_id]);
        }

        // الأقسام/المشاريع التي تمنحها أدوار المستخدم السياقية الرؤية (الموقع الصاعد).
        $scopes = $engineScopes;
        $roleDeptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);
        $projectIds = $scopes['project'] ?? [];

        $allDeptIds = array_values(array_unique(array_merge($deptIds, $roleDeptIds)));

        return $query->where(function (Builder $q) use ($user, $allDeptIds, $projectIds, $governedTypes) {
            $this->whereDirectlyRelated($q, $user);

            if ($allDeptIds !== []) {
                $q->orWhereIn('department_id', $allDeptIds);
            }
            if ($projectIds !== []) {
                $q->orWhereIn('id', $projectIds);
            }
            // Governed types (e.g. Quality oversees 'improvement') are visible
            // org-wide; org isolation is already applied above.
            if ($governedTypes !== []) {
                $q->orWhereIn('type', $governedTypes);
            }
        });
    }

    /**
     * الارتباط المباشر بالمشروع: منشئ/عضو/دور سياقي/صاحب مصلحة/مكلّف بمهمة.
     */
    protected function whereDirectlyRelated(Builder $q, User $user): void
    {
        $q->where('created_by', $user->id)
            ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id))
            ->orWhereHas('scopedRoles', fn (Builder $r) => $r->where('user_id', $user->id))
            ->orWhereHas('stakeholders', fn (Builder $s) => $s->where('user_id', $user->id))
            ->orWhereHas('tasks', fn (Builder $t) => $t->where('assigned_to', $user->id));
    }

    /**
     * نسخة مطابقة للاستعلامات المتداخلة (whereHas('project')). تستخدم نفس منطق
     * السلّم؛ موجودة للحفاظ على واجهة applySimple التي يعتمدها UserTaskScope.
     */
    public function applySimple(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user);
    }
}
