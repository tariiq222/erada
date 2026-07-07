<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\UpdateGovernanceRuleRequest;
use App\Modules\Core\Models\GovernanceRule;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified "governing departments" admin surface (ADR-UNIFIED-ROLE-ACCESS, Phase 5).
 * One screen replaces the three scattered per-module settings (Risk/OVR/Project):
 * each governed resource type points at the department that oversees it org-wide.
 * Reads/writes the single `governance_rules` source via the GovernanceRule model.
 */
class GovernanceRulesController extends Controller
{
    /**
     * The governable resource types shown on the screen. Arabic labels are display
     * data (like label_ar columns); `capabilities` is the org-wide grant the governing
     * unit's members receive over that resource type.
     *
     * @var array<string, array{label: string, capabilities: array<int, string>}>
     */
    private const RESOURCE_TYPES = [
        GovernanceRule::TYPE_PROJECT => [
            'label' => 'المشاريع',
            'capabilities' => ['projects.view', 'projects.create', 'projects.edit', 'projects.delete'],
        ],
        GovernanceRule::TYPE_RISK => [
            'label' => 'المخاطر',
            'capabilities' => ['risks.view', 'risks.create', 'risks.edit', 'risks.delete'],
        ],
        GovernanceRule::TYPE_OVR => [
            'label' => 'بلاغات الحوادث',
            'capabilities' => ['ovr.view_all'],
        ],
    ];

    /**
     * List the current governing department per resource type for the caller's org.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        // One name lookup for every governing unit currently set (no N+1).
        $unitIds = [];
        $resolved = [];
        foreach (array_keys(self::RESOURCE_TYPES) as $type) {
            $resolved[$type] = GovernanceRule::governingUnitId($orgId, $type);
            if ($resolved[$type] !== null) {
                $unitIds[] = $resolved[$type];
            }
        }
        $names = Department::whereIn('id', array_unique($unitIds))->pluck('name', 'id');

        $rows = [];
        foreach (self::RESOURCE_TYPES as $type => $meta) {
            $unitId = $resolved[$type];
            $rows[] = [
                'resource_type' => $type,
                'label' => $meta['label'],
                'governing_unit_id' => $unitId,
                'governing_unit_name' => $unitId !== null ? ($names[$unitId] ?? null) : null,
                'applies_to_children' => true,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Set (or clear, when governing_unit_id is null) the governing department for a
     * resource type in the caller's organization.
     */
    public function update(UpdateGovernanceRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $type = $validated['resource_type'];

        GovernanceRule::setGoverningUnit(
            organizationId: $request->user()->organization_id,
            resourceType: $type,
            subtype: $validated['resource_subtype'] ?? null,
            governingUnitId: $validated['governing_unit_id'] ?? null,
            capabilities: self::RESOURCE_TYPES[$type]['capabilities'],
        );

        return response()->json(['message' => 'تم تحديث الإدارة الحاكمة']);
    }
}
