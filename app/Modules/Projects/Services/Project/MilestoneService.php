<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;

class MilestoneService
{
    /**
     * إنشاء المراحل والمخرجات لمشروع
     *
     * @return array<int, int> قائمة معرفات المراحل مفهرسة بالترتيب
     */
    public function createMilestones(Project $project, array $milestones): array
    {
        $milestoneIds = [];

        foreach ($milestones as $order => $milestoneData) {
            if ($this->isEmptyMilestone($milestoneData)) {
                continue;
            }

            $milestone = $this->createMilestone($project, $milestoneData, $order);
            $milestoneIds[$order] = $milestone->id;

            $this->createDeliverables($milestone, $milestoneData['deliverables'] ?? []);
        }

        return $milestoneIds;
    }

    /**
     * إنشاء مرحلة واحدة
     */
    public function createMilestone(Project $project, array $data, int $order = 0): Milestone
    {
        return $project->milestones()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'],
            'due_date' => $data['due_date'],
            'order' => $order + 1,
            'status' => $data['status'] ?? 'pending',
            'progress' => $data['progress'] ?? 0,
        ]);
    }

    /**
     * تحديث مرحلة
     */
    public function updateMilestone(Milestone $milestone, array $data): Milestone
    {
        $milestone->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? null,
            'progress' => $data['progress'] ?? null,
        ], fn ($value) => $value !== null));

        return $milestone->fresh();
    }

    /**
     * حذف مرحلة
     */
    public function deleteMilestone(Milestone $milestone): bool
    {
        // حذف المخرجات أولاً
        $milestone->deliverables()->delete();

        return $milestone->delete();
    }

    /**
     * إنشاء المخرجات لمرحلة
     */
    public function createDeliverables(Milestone $milestone, array $deliverables): void
    {
        foreach ($deliverables as $order => $deliverableData) {
            if (empty($deliverableData['name'])) {
                continue;
            }

            $milestone->deliverables()->create([
                'name' => $deliverableData['name'],
                'description' => $deliverableData['description'] ?? null,
                'order' => $order + 1,
                'status' => $deliverableData['status'] ?? 'pending',
                'progress' => $deliverableData['progress'] ?? 0,
            ]);
        }
    }

    /**
     * مزامنة المراحل عند تحديث المشروع (upsert بالـ id).
     *
     * - مرحلة تحمل id موجوداً ضمن المشروع → تحديث + إضافة المخرجات الجديدة (بلا id).
     * - مرحلة بلا id → إنشاء جديدة مع مخرجاتها.
     *
     * لا تُحذف المراحل غير المذكورة (قد تُدار عبر نقاط نهاية المراحل المخصصة).
     * يحلّ محلّ السلوك السابق الذي كان يُسقط المراحل بصمت عند التحديث.
     *
     * @return array<int, int> خريطة index→id للمراحل (لربط milestone_index بالمهام الجديدة)
     */
    public function syncMilestones(Project $project, array $milestones): array
    {
        $existingIds = array_map('intval', $project->milestones()->pluck('id')->all());
        $milestoneIds = [];

        foreach ($milestones as $order => $milestoneData) {
            if ($this->isEmptyMilestone($milestoneData)) {
                continue;
            }

            $id = $milestoneData['id'] ?? null;
            if ($id && in_array((int) $id, $existingIds, true)) {
                $milestone = $project->milestones()->whereKey($id)->first();
                if ($milestone) {
                    $this->updateMilestone($milestone, $milestoneData);
                    // مخرجات جديدة فقط (التي بلا id) لتفادي التكرار
                    $newDeliverables = array_values(array_filter(
                        $milestoneData['deliverables'] ?? [],
                        fn ($d) => empty($d['id']) && ! empty($d['name'])
                    ));
                    $this->createDeliverables($milestone, $newDeliverables);
                    $milestoneIds[$order] = (int) $milestone->id;

                    continue;
                }
            }

            $milestone = $this->createMilestone($project, $milestoneData, $order);
            $this->createDeliverables($milestone, $milestoneData['deliverables'] ?? []);
            $milestoneIds[$order] = (int) $milestone->id;
        }

        return $milestoneIds;
    }

    /**
     * @deprecated Reorder via syncMilestones or directly on the `milestones()` relationship. This method has no callers in the codebase.
     */
    public function reorderMilestones(Project $project, array $orderedIds): void
    {
        foreach ($orderedIds as $order => $milestoneId) {
            $project->milestones()
                ->where('id', $milestoneId)
                ->update(['order' => $order + 1]);
        }
    }

    /**
     * التحقق إذا كانت بيانات المرحلة فارغة
     */
    protected function isEmptyMilestone(array $data): bool
    {
        return empty($data['name'])
            || empty($data['start_date'])
            || empty($data['due_date']);
    }
}
