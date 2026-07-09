<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Policies\ProgramPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewProgramRequest - engine-only authz for reading a single program.
 *
 * Phase 9-D-D1b — delegates to ProgramPolicy::view() which contains the
 * cluster_tree read widening (STRATEGY_VIEW + CLUSTER_TREE_VIEW on actor
 * ⇒ cross-org read for descendant organizations). The engine handles
 * super_admin bypass + organization isolation (Program is ScopeAware).
 */
class ViewProgramRequest extends FormRequest
{
    protected ?Program $program = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $program = $this->route('program');

        if (! $program instanceof Program) {
            $program = Program::find($program);
        }

        if (! $program) {
            return true;
        }

        $this->program = $program;

        return app(ProgramPolicy::class)->view($user, $program);
    }

    public function rules(): array
    {
        return [];
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }
}
