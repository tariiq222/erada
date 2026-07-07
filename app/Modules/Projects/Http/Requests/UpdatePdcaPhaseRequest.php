<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePdcaPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        if (! $project) {
            return false;
        }

        return $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'phase' => ['required', 'string', 'in:plan,do,check,act'],
            'measurements' => ['array'],
            'measurements.*.kpi_id' => ['required', 'integer'],
            'measurements.*.value' => ['required', 'numeric'],
            'measurements.*.measurement_date' => ['required', 'date'],
        ];
    }
}
