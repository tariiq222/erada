<?php

namespace App\Modules\Projects\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserTaskScope - تطبيق فلتر صلاحيات المستخدم على استعلامات المهام
 *
 * نموذج الرؤية إضافي (OR) لا بوّابة صلبة، موازٍ لـ UserProjectScope. المستخدم
 * (غير super_admin) يرى مهمة إذا تحقّق أيٌّ مما يلي:
 *   - ارتباط مباشر بالمهمة: مكلّف / منشئ / مالك (دائماً).
 *   - مهام مشاريع يحمل عليها دوراً سياقياً (project scope) أو مشاريع أقسام تمنحها
 *     أدواره السياقية على القسم (مع الشجرة الهابطة).
 *   - توسعة السلّم المسطّح: view_tasks → كل مهام المؤسسة؛ view_department_tasks →
 *     مهام مشاريع قسمه وشجرته.
 *   - بلا أي ارتباط ولا منحة ولا صلاحية → لا شيء.
 *
 * super_admin يرى الكل. عزل المؤسسة يُطبَّق عبر فلتر المشروع (مهمة بلا مشروع
 * تبقى محكومة بالارتباط المباشر فقط). الصلاحية المسطّحة تزيد المدى ولا تُعدّ
 * شرطاً مسبقاً لرؤية المهام المرتبطة مباشرة أو عبر دور سياقي.
 */
class UserTaskScope
{
    public function __construct(
        protected UserProjectScope $projectScope
    ) {}

    /**
     * تطبيق فلتر الصلاحيات على استعلام المهام
     *
     * نطاق إضافي (OR): الارتباط المباشر بالمهمة + مهام المشاريع التي يحمل المستخدم
     * عليها دوراً سياقياً (project scope) + مهام مشاريع الأقسام التي تمنحها أدواره
     * السياقية على القسم (مع الشجرة الهابطة) + توسعة السلّم المسطّح. عمداً لا نمرّر
     * عبر UserProjectScope::apply الكامل هنا، لأن قاعدة «المكلّف بمهمة ⇒ المشروع
     * مرئي» فيه ستوسّع رؤية المهام إلى كل مهام المشروع لمجرد إسناد مهمة واحدة —
     * وهو ما يخالف عقد view_own_tasks (المهام المباشرة فقط).
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $hasFlatViewTasks = AccessDecision::grantsAtOrganization($user, Capability::TASKS_VIEW);

        // المشاريع/الأقسام التي تمنحها أدوار المستخدم السياقية رؤيةَ المشاريع
        // (الموقع الصاعد). دور على القسم يوسَّع إلى شجرته الهابطة.
        $scopes = AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW);
        $projectIds = $scopes['project'] ?? [];
        $deptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);

        // توسعة السلّم المسطّح على مستوى القسم: مهام مشاريع قسمه وشجرته الهابطة.
        $deptScopes = AccessDecision::subtreeDepartmentIds(
            AccessDecision::grantingScopes($user, Capability::TASKS_VIEW)['department'] ?? []
        );
        if ($deptScopes !== [] && $user->department_id) {
            $deptIds = array_values(array_unique(array_merge(
                $deptIds,
                $deptScopes
            )));
        }

        return $query->where(function (Builder $q) use ($user, $hasFlatViewTasks, $projectIds, $deptIds) {
            // الارتباط المباشر بالمهمة (دائماً): مكلّف/منشئ/مالك.
            $this->whereDirectlyRelated($q, $user);

            // view_tasks المسطّحة → كل مهمة ضمن مؤسسة المستخدم (لها مشروع في مؤسسته).
            if ($hasFlatViewTasks && $user->organization_id) {
                $q->orWhereHas('project', fn (Builder $p) => $p->where('organization_id', $user->organization_id));
            }

            // مهام مشاريع/أقسام منحها المحرّك (أدوار سياقية على مشروع أو قسم) أو
            // توسعة القسم المسطّحة.
            if ($projectIds !== [] || $deptIds !== []) {
                $q->orWhereHas('project', function (Builder $p) use ($projectIds, $deptIds) {
                    $p->where(function (Builder $inner) use ($projectIds, $deptIds) {
                        if ($projectIds !== []) {
                            $inner->orWhereIn('id', $projectIds);
                        }
                        if ($deptIds !== []) {
                            $inner->orWhereIn('department_id', $deptIds);
                        }
                    });
                });
            }
        });
    }

    /**
     * فلتر عبر المشروع فقط (بلا الارتباط المباشر) — للاستعلامات التي تنطلق من
     * سياق المشروع. يستخدم سلّم رؤية المشاريع نفسه.
     */
    public function applyViaProject(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('project', fn (Builder $p) => $this->projectScope->apply($p, $user));
    }

    /**
     * الارتباط المباشر بالمهمة: مكلّف/منشئ/مالك.
     */
    protected function whereDirectlyRelated(Builder $q, User $user): void
    {
        $q->where('assigned_to', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhere('owner_id', $user->id);
    }
}
