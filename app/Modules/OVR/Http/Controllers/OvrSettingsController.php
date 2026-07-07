<?php

namespace App\Modules\OVR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Http\Requests\UpdateGoverningDepartmentRequest;
use App\Modules\OVR\Models\OvrSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin settings for the OVR module. Currently exposes the governing department
 * (members of its subtree may create reports for any department and see all
 * reports org-wide). Mirrors RiskSettingsController governing endpoints.
 */
class OvrSettingsController extends Controller
{
    /**
     * Read the configured governing department for OVR plus the selectable
     * departments. Admin-gated (super_admin / manage_organization).
     */
    public function getGoverningDepartment(Request $request): JsonResponse
    {
        $this->authorizeGovernance($request);

        $user = $request->user();

        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'level')
            ->orderBy('level')
            ->orderBy('name')
            ->get()
            ->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
                'level' => $dept->level,
                'level_name' => $dept->getLevelNameAttribute(),
            ]);

        return response()->json([
            'department_id' => OvrSetting::getGoverningDepartmentId(),
            'departments' => $departments,
        ]);
    }

    /**
     * Update (or clear) the governing department for OVR. Admin-gated.
     */
    public function updateGoverningDepartment(UpdateGoverningDepartmentRequest $request): JsonResponse
    {
        // Authorization and the "department belongs to your organization" rule are
        // handled by UpdateGoverningDepartmentRequest (engine-first via
        // SETTINGS_MANAGE, plus withValidator after-hook).

        $departmentId = $request->input('department_id');

        OvrSetting::setGoverningDepartmentId($departmentId !== null ? (int) $departmentId : null);

        return response()->json([
            'message' => __('ovr.api.governing_department_updated'),
            'department_id' => OvrSetting::getGoverningDepartmentId(),
        ]);
    }

    private function authorizeGovernance(Request $request): void
    {
        $user = $request->user();

        if (! AccessDecision::can($user, Capability::SETTINGS_MANAGE)) {
            abort(403, __('ovr.api.access_denied'));
        }
    }
}
