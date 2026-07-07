<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Program;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ViewProgramRequest - engine-only authz for reading a single program.
 * Surfaces STRATEGY_VIEW against the resolved program; engine handles
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

        return AccessDecision::can($user, Capability::STRATEGY_VIEW, $program);
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
