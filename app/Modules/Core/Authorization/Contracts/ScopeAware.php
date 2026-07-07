<?php

namespace App\Modules\Core\Authorization\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * ScopeAware — عقد النماذج الهدف في محرّك AuthZ
 *
 * كل نموذج يريد المشاركة في نظام القرار الموحّد يجب أن يطبّق هذا العقد.
 * يتيح للمحرّك صعود سلسلة الأب (scope chain) حتى المؤسسة لاتخاذ قرار المنح/المنع.
 */
interface ScopeAware
{
    /**
     * النموذج الأب المباشر في سلسلة النطاق.
     * يُعيد null إذا كان هذا النموذج جذراً (أعلى السلسلة).
     *
     * أمثلة:
     *   Task → Project
     *   Project → Department
     *   Department → (Department الأب أو null للجذر)
     *   Program → Portfolio
     *   Portfolio → null
     *   Risk → Department أو riskable (Project)
     *   IncidentReport → Department
     */
    public function scopeParent(): ?Model;

    /**
     * مفتاح نوع النطاق المستخدم في ScopedRole.scope_type و ScopeType.key.
     * القيم المعتمدة: 'project'|'task'|'department'|'program'|'portfolio'|'risk'|'incident'
     */
    public function scopeTypeKey(): string;

    /**
     * معرّف المؤسسة (organization_id) المشتق من هذا النموذج أو من آبائه.
     * يُعيد null إذا لم يمكن اشتقاقه (مثل Task شخصية بلا مشروع).
     */
    public function scopeOrganizationId(): ?int;
}
