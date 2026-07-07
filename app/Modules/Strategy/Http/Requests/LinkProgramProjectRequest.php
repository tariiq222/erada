<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Program;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LinkProgramProjectRequest - engine-only authz + payload validation for
 * linking a project into a program. Surfaces STRATEGY_EDIT against the
 * program. Cross-row checks (target project belongs to the same org, not
 * already linked) stay in the controller — they depend on the loaded
 * project and need to surface as 422 state errors.
 */
class LinkProgramProjectRequest extends FormRequest
{
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

        return AccessDecision::can($user, Capability::STRATEGY_EDIT, $program);
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
