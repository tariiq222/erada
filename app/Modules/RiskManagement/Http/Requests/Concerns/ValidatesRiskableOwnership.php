<?php

namespace App\Modules\RiskManagement\Http\Requests\Concerns;

use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Tasks\Models\Task;

/**
 * Shared riskable (polymorphic parent) ownership validation for the Store/Update
 * risk requests (M-08). Without this on update, a risk could be re-pointed at a
 * riskable belonging to another organization. Anti-enumeration: a missing id and
 * a cross-org id reject with the same neutral message.
 */
trait ValidatesRiskableOwnership
{
    protected function allowedRiskableAliases(): array
    {
        return ['project', 'program', 'portfolio', 'task'];
    }

    public function resolveRiskableClass(string $alias): string
    {
        return match ($alias) {
            'project' => Project::class,
            'program' => Program::class,
            'portfolio' => Portfolio::class,
            'task' => Task::class,
            default => throw new \InvalidArgumentException('نوع العنصر المرتبط غير صالح'),
        };
    }

    protected function validateRiskableOwnership($v): void
    {
        $type = $this->input('riskable_type');
        $id = $this->input('riskable_id');

        if (! $type || ! $id) {
            return;
        }

        try {
            $class = $this->resolveRiskableClass($type);
        } catch (\InvalidArgumentException $e) {
            $v->errors()->add('riskable_type', $e->getMessage());

            return;
        }

        $model = $class::find($id);
        if (! $model) {
            $v->errors()->add('riskable_id', 'العنصر المرتبط غير صالح');

            return;
        }

        $user = $this->user();
        if (! $user?->isSuperAdmin() && (int) $model->organization_id !== (int) $user->organization_id) {
            $v->errors()->add('riskable_id', 'العنصر المرتبط غير صالح');
        }
    }
}
