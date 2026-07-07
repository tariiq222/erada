<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Strategy\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LinkProjectRequest - validation + engine-only authz for linking a Project
 * to a Program.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated the project_id payload inline.
 * authorize() now resolves strategy.edit through AccessDecision::can() against
 * the bound Program; the organization guard stays in the controller (it
 * depends on the bound model and the existing trait flow).
 */
class LinkProjectRequest extends FormRequest
{
    protected ?Program $program = null;

    /**
     * Engine-only authorization for linking a project to a program.
     */
    public function authorize(): bool
    {
        $program = $this->route('program');

        if (! $program instanceof Program) {
            return false;
        }

        $this->program = $program;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_EDIT, $program);
    }

    /**
     * Validation rules for the link-project payload.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
        ];
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'معرّف المشروع',
        ];
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }
}
