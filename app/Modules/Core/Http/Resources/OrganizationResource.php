<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Models\Organization;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OrganizationResource — Phase 9-C JSON shape.
 *
 * يُستبدل الـ transform() الخاص بـ OrganizationController.
 * الحقول الجديدة (Phase 9-B / 9-C):
 *   - type, parent_id, sort_order
 *   - parent summary (id + name فقط) — مقيد بصلاحية super_admin
 *   - children_count (active فقط)
 *   - is_root (helper للـ frontend)
 *
 * ملاحظة: مع `counts=true` يُحمَّل users_count و projects_count أيضًا.
 */
class OrganizationResource extends JsonResource
{
    /**
     * هل نُحمّل counts؟
     * يُمرَّر من الـ controller عند الـ show endpoint.
     */
    private bool $withCounts = false;

    /**
     * هل نُضمّ parent summary (id + name)؟
     * يُمرَّر عند الحاجة — افتراضيًا false للحفاظ على حجم list response.
     */
    private bool $withParent = false;

    public function withCounts(bool $value = true): self
    {
        $this->withCounts = $value;

        return $this;
    }

    public function withParent(bool $value = true): self
    {
        $this->withParent = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var Organization $org */
        $org = $this->resource;

        $data = [
            'id' => (int) $org->id,
            'name' => $org->name,
            'code' => $org->code,
            'type' => $org->type,
            'parent_id' => $org->parent_id !== null ? (int) $org->parent_id : null,
            'sort_order' => (int) ($org->sort_order ?? 0),
            'description' => $org->description,
            'email' => $org->email,
            'phone' => $org->phone,
            'address' => $org->address,
            'website' => $org->website,
            'logo' => $org->logo,
            'is_active' => (bool) $org->is_active,
            'is_root' => $org->isRoot(),
            'can_have_children' => $org->canHaveChildren(),
            'allowed_child_types' => $org->allowedChildTypes(),
            'children_count' => (int) $org->activeChildrenCount(),
            'created_at' => $org->created_at?->toIso8601String(),
            'updated_at' => $org->updated_at?->toIso8601String(),
        ];

        if ($this->withCounts) {
            $data['users_count'] = (int) ($org->users_count ?? 0);
            $data['projects_count'] = (int) ($org->projects_count ?? 0);
        }

        if ($this->withParent && $org->parent_id !== null) {
            $parent = $org->parent;
            $data['parent'] = $parent ? [
                'id' => (int) $parent->id,
                'name' => $parent->name,
                'code' => $parent->code,
                'type' => $parent->type,
            ] : null;
        } else {
            $data['parent'] = null;
        }

        return $data;
    }
}
