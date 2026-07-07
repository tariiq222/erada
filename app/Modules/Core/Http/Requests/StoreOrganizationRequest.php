<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\Organization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreOrganizationRequest - التحقق من بيانات إنشاء مؤسسة
 *
 * صلاحية الإنشاء محصورة بـ super_admin عبر middleware على المسار (route group).
 * لا توجد سياسة Policy لـ Organization (نموذج إدارة نظامي خالص).
 *
 * Phase 9-C — يضيف:
 *   - type (default 'organization')
 *   - parent_id (optional, hierarchical)
 *   - sort_order (optional, default 0)
 *
 * قواعد hierarchy (Phase 9-B + Phase 9-C):
 *   - cluster: root فقط (لا يقبل parent)
 *   - hospital/center/other: root فقط أيضًا في هذه المرحلة
 *   - organization: root أو ابن لـ cluster فقط
 *   - parent.type ∈ canAcceptChildType(newType)
 *   - parent.is_active = true
 */
class StoreOrganizationRequest extends FormRequest
{
    /**
     * صلاحية الإنشاء محصورة بـ super_admin على مستوى المسار.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:organizations,code'],
            'type' => ['nullable', 'string', Rule::in(Organization::TYPES)],
            'parent_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:255'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * حقول افتراضية
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->input('type', Organization::TYPE_ORGANIZATION),
            'sort_order' => $this->input('sort_order', 0),
            'is_active' => $this->input('is_active', true),
        ]);
    }

    /**
     * قواعد hierarchy بعد التحقق من القواعد البسيطة.
     *
     * يُفحص:
     *   - cluster لا يُقبل بـ parent_id
     *   - hospital/center/other لا يُقبلون بـ parent_id في Phase 9-C
     *     (الـ ALLOWED_CHILD_TYPES map على cluster لا يحتويهم، فالنتيجة نفسها)
     *   - parent.is_active = true
     *   - parent.canAcceptChildType(newType) = true
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = $this->input('type');
            $parentId = $this->input('parent_id');

            if ($type === Organization::TYPE_CLUSTER && $parentId !== null) {
                $v->errors()->add(
                    'parent_id',
                    'المؤسسة من نوع cluster يجب أن تكون جذرًا (لا يُقبل parent_id).'
                );

                return;
            }

            if ($parentId !== null) {
                $this->validateParent($v, $parentId, $type);
            }
        });
    }

    /**
     * تحقّق من صلاحية الـ parent المُحدَّد: is_active + canAcceptChildType.
     */
    private function validateParent(Validator $v, int $parentId, string $type): void
    {
        $parent = Organization::find($parentId);

        if ($parent === null) {
            return; // exists rule سيعطي رسالة أوضح
        }

        if (! $parent->is_active) {
            $v->errors()->add(
                'parent_id',
                'لا يمكن ربط مؤسسة بمؤسسة أم غير فعّالة.'
            );
        }

        if (! $parent->canAcceptChildType($type)) {
            $v->errors()->add(
                'parent_id',
                "المؤسسة الأم من نوع {$parent->type} لا تقبل ابنًا من نوع {$type}."
            );
        }
    }
}
