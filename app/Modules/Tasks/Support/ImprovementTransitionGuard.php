<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Tasks\Models\Task;

/**
 * يفرض توثيق PDCA عند نقل مهام مشاريع التحسين إلى المراجعة/الإكمال.
 */
class ImprovementTransitionGuard
{
    public static function check(Task $task, ?string $newStatus, array $data): ?string
    {
        if (! $newStatus) {
            return null;
        }

        $task->loadMissing('project');
        if (($task->project->type ?? null) !== 'improvement') {
            return null;
        }

        if ($newStatus === 'in_review' && blank($data['status_comment'] ?? null)) {
            return 'يجب توثيق "وش نُفّذ؟" قبل إرسال المهمة للمراجعة (منهجية PDCA).';
        }

        if ($newStatus === 'completed' && blank($data['lessons_learned'] ?? null)) {
            return 'يجب توثيق "الدرس وقرار التعميم" قبل إكمال المهمة (منهجية PDCA).';
        }

        return null;
    }
}
