<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRisk;
use Illuminate\Database\Eloquent\Collection;

class RiskService
{
    /**
     * إنشاء المخاطر لمشروع
     */
    public function createRisks(Project $project, array $risks): void
    {
        foreach ($risks as $order => $riskData) {
            if (empty($riskData['description'])) {
                continue;
            }

            $this->createRisk($project, $riskData, $order);
        }
    }

    /**
     * إنشاء مخاطرة واحدة
     */
    public function createRisk(Project $project, array $data, int $order = 0): ProjectRisk
    {
        return $project->risks()->create([
            'risk' => $data['description'] ?? $data['risk'],
            'probability' => $data['probability'] ?? 'medium',
            'impact' => $data['impact'] ?? 'medium',
            'response' => $data['mitigation'] ?? $data['response'] ?? null,
            'status' => $data['status'] ?? 'open',
            'order' => $order + 1,
        ]);
    }

    /**
     * مزامنة المخاطر مع الحفاظ على الهوية والحالة والتاريخ.
     *
     * - عنصر يحمل id موجود ضمن المشروع → تحديث (يحفظ status/primary key/سجل النشاط).
     * - عنصر بلا id → إنشاء جديد.
     * - مخاطرة موجودة غير مذكورة في الحمولة → حذف.
     *
     * يحلّ محلّ السلوك السابق (delete-and-recreate) الذي كان يمسح كل الحالات
     * ويُرجعها إلى open في كل حفظة.
     */
    public function syncRisks(Project $project, array $risks): void
    {
        $existingIds = array_map('intval', $project->risks()->pluck('id')->all());
        $keptIds = [];
        $order = 0;

        foreach ($risks as $riskData) {
            $description = $riskData['description'] ?? $riskData['risk'] ?? null;
            if (empty($description)) {
                continue;
            }

            $id = $riskData['id'] ?? null;
            if ($id && in_array((int) $id, $existingIds, true)) {
                $risk = $project->risks()->whereKey($id)->first();
                if ($risk) {
                    $risk->update(array_filter([
                        'risk' => $description,
                        'probability' => $riskData['probability'] ?? null,
                        'impact' => $riskData['impact'] ?? null,
                        'response' => $riskData['mitigation'] ?? $riskData['response'] ?? null,
                        'status' => $riskData['status'] ?? null,
                        'order' => $order + 1,
                    ], fn ($value) => $value !== null));
                    $keptIds[] = (int) $risk->id;
                }
            } else {
                $created = $this->createRisk($project, $riskData, $order);
                $keptIds[] = (int) $created->id;
            }

            $order++;
        }

        $toDelete = array_diff($existingIds, $keptIds);
        if (! empty($toDelete)) {
            $project->risks()->whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * تحديث مخاطرة قائمة
     *
     * يقبل الحقول المعروفة في createRisk (description/risk, probability, impact,
     * mitigation/response, status, order) ويربطها بأعمدة ProjectRisk.
     */
    public function updateRisk(ProjectRisk $risk, array $data): ProjectRisk
    {
        $updates = [];

        if (array_key_exists('description', $data) || array_key_exists('risk', $data)) {
            $updates['risk'] = $data['description'] ?? $data['risk'];
        }
        if (array_key_exists('probability', $data)) {
            $updates['probability'] = $data['probability'];
        }
        if (array_key_exists('impact', $data)) {
            $updates['impact'] = $data['impact'];
        }
        if (array_key_exists('mitigation', $data) || array_key_exists('response', $data)) {
            $updates['response'] = $data['mitigation'] ?? $data['response'];
        }
        if (array_key_exists('status', $data)) {
            $updates['status'] = $data['status'];
        }
        if (array_key_exists('order', $data)) {
            $updates['order'] = $data['order'];
        }

        if (! empty($updates)) {
            $risk->update($updates);
        }

        return $risk->fresh();
    }

    /**
     * حذف مخاطرة
     */
    public function deleteRisk(ProjectRisk $risk): bool
    {
        return (bool) $risk->delete();
    }

    /**
     * استبدال كامل لقائمة المخاطر (حذف القديم ثم إضافة الجديد)
     *
     * للمزامنة الذكية (الحفاظ على الهوية والحالة) استخدم syncRisks().
     */
    public function replaceRisks(Project $project, array $risks): void
    {
        $project->risks()->delete();
        $this->createRisks($project, $risks);
    }

    /**
     * تغيير حالة مخاطرة (open | mitigated | closed)
     */
    public function changeStatus(ProjectRisk $risk, string $status): ProjectRisk
    {
        $risk->update(['status' => $status]);

        return $risk->fresh();
    }

    /**
     * المخاطر المفتوحة لمشروع
     */
    public function getOpenRisks(Project $project): Collection
    {
        return $project->risks()->where('status', 'open')->get();
    }

    /**
     * المخاطر عالية الأثر (impact = high) المفتوحة لمشروع
     */
    public function getHighImpactRisks(Project $project): Collection
    {
        return $project->risks()
            ->where('impact', 'high')
            ->where('status', 'open')
            ->get();
    }
}
