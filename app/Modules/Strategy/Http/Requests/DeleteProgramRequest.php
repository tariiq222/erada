<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Program;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteProgramRequest - engine-only authz for deleting a program.
 * Surfaces STRATEGY_DELETE against the resolved program.
 */
class DeleteProgramRequest extends FormRequest
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

        return AccessDecision::can($user, Capability::STRATEGY_DELETE, $program);
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
