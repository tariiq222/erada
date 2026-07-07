<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\Organization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateOrganizationRequest - التحقق من بيانات تحديث مؤسسة
 *
 * صلاحية التحديث محصورة بـ super_admin عبر middleware على المسار.
 *
 * Phase 9-C — يضيف قواعد hierarchy:
 *   - parent_id لا يساوي id
 *   - لا تكرار دورة: parent الجديد ليس سليلًا للمنظمة الحالية
 *   - parent.is_active = true
 *   - parent.canAcceptChildType(this.type) = true
 *   - type change: لا يمكن التحويل من cluster/hospital/center إلى نوع
 *     لا يقبل children إذا كانت المؤسسة لديها children حاليًا
 */
class UpdateOrganizationRequest extends FormRequest
{
    /**
     * صلاحية التحديث محصورة بـ super_admin على مستوى المسار.
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
        $organization = $this->route('organization');
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('organizations', 'code')->ignore($organizationId)],
            'type' => ['sometimes', 'string', Rule::in(Organization::TYPES)],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:255'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * قواعد hierarchy بعد التحقق من القواعد البسيطة.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $organization = $this->route('organization');
            if (! $organization instanceof Organization) {
                return;
            }

            $newType = $this->input('type', $organization->type);
            $newParentId = $this->has('parent_id') ? $this->input('parent_id') : $organization->parent_id;

            $this->validateParentChange($v, $organization, $newParentId);
            $this->validateTypeChange($v, $organization, $newType, $newParentId);
        });
    }

    /**
     * تحقّق من قواعد parent_id:
     *   - لا self-reference
     *   - لا cycle
     *   - parent.is_active = true
     *   - parent.canAcceptChildType(newType) = true
     */
    private function validateParentChange(Validator $v, Organization $organization, mixed $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        $newParentId = (int) $newParentId;
        $hasSelfOrCycleError = $this->rejectSelfOrCycle($v, $organization, $newParentId);
        if ($hasSelfOrCycleError) {
            return;
        }

        $parent = Organization::find($newParentId);
        if ($parent === null) {
            return; // exists rule سيعطي رسالة أوضح
        }

        if (! $parent->is_active) {
            $v->errors()->add(
                'parent_id',
                'لا يمكن ربط مؤسسة بمؤسسة أم غير فعّالة.'
            );
        }

        $newType = $this->input('type', $organization->type);
        if (! $parent->canAcceptChildType($newType)) {
            $v->errors()->add(
                'parent_id',
                "المؤسسة الأم من نوع {$parent->type} لا تقبل ابنًا من نوع {$newType}."
            );
        }
    }

    /**
     * ارفض self-reference و cycle. يُرجع true إذا وُجد خطأ.
     */
    private function rejectSelfOrCycle(Validator $v, Organization $organization, int $newParentId): bool
    {
        if ($newParentId === (int) $organization->id) {
            $v->errors()->add(
                'parent_id',
                'لا يمكن للمؤسسة أن تكون ابنًا لنفسها.'
            );

            return true;
        }

        if ($this->isDescendantOf($organization, $newParentId)) {
            $v->errors()->add(
                'parent_id',
                'لا يمكن نقل المؤسسة تحت أحد أبنائها (يخلق حلقة).'
            );

            return true;
        }

        return false;
    }

    /**
     * تحقّق من قواعد type change:
     *   - cluster لا يمكن أن يكون له parent (حتى لو الـ type لم يتغير، parent_id قد يتغير)
     *   - لا يمكن التحويل من cluster إلى نوع لا يقبل children إذا عندنا children
     */
    private function validateTypeChange(Validator $v, Organization $organization, string $newType, mixed $newParentId): void
    {
        // cluster يجب أن يكون root
        if ($newType === Organization::TYPE_CLUSTER && $newParentId !== null) {
            $v->errors()->add(
                'parent_id',
                'المؤسسة من نوع cluster يجب أن تكون جذرًا (لا يُقبل parent_id).'
            );
        }

        // إذا الـ type يتغير والنوع الجديد لا يقبل children، امنع إذا عندنا children
        $oldType = $organization->type;
        if ($newType !== $oldType && $organization->hasChildren()) {
            $oldCanHaveChildren = in_array($oldType, array_keys(array_filter(
                Organization::ALLOWED_CHILD_TYPES,
                fn ($allowed) => $allowed !== []
            )), true);
            $newCanHaveChildren = in_array($newType, array_keys(array_filter(
                Organization::ALLOWED_CHILD_TYPES,
                fn ($allowed) => $allowed !== []
            )), true);

            if ($oldCanHaveChildren && ! $newCanHaveChildren) {
                $v->errors()->add(
                    'type',
                    "لا يمكن تغيير نوع المؤسسة من {$oldType} إلى {$newType} لوجود منظمات تابعة لها."
                );
            }
        }
    }

    /**
     * هل newParentId هو ابن (أو حفيد) لـ organization؟
     * إذا نعم، تعيين organization.parent_id = newParentId يخلق cycle.
     */
    private function isDescendantOf(Organization $organization, int $newParentId): bool
    {
        $visited = [];
        $current = Organization::find($newParentId);

        while ($current !== null) {
            if ((int) $current->id === (int) $organization->id) {
                return true;
            }
            if (in_array((int) $current->id, $visited, true)) {
                return true; // pre-existing cycle (data corruption) — رفض defensive
            }
            $visited[] = (int) $current->id;
            if ($current->parent_id === null) {
                break;
            }
            $current = Organization::find($current->parent_id);
        }

        return false;
    }
}
