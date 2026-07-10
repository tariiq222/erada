<?php

namespace Database\Seeders;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRoleDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScopedDepartmentRolesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $deptScopeId = DB::table('scope_types')->where('key', 'department')->value('id')
            ?? DB::table('scope_types')->insertGetId([
                'key' => 'department',
                'label_ar' => 'القسم',
                'label_en' => 'Department',
                'model_class' => 'App\\Modules\\HR\\Models\\Department',
                'icon' => null,
                'color' => 'primary',
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $orgScopeId = DB::table('scope_types')->where('key', 'organization')->value('id');

        // NOTE: use Eloquent updateOrCreate (not DB::updateOrInsert) so created_at is
        // stamped on insert only and NOT reset on every re-run. permissions is cast to
        // array on the model, so no manual json_encode.
        //
        // The scoped_role_definitions table still carries the legacy NOT NULL columns
        // name / display_name / scope_type (string) from the 2026_01_12 schema. They are
        // not on the model's $fillable, so they are force-filled below per definition.

        $definitions = [
            // Department-scoped capacity roles
            [
                'scope_type_id' => $deptScopeId,
                'scope_type_key' => 'department',
                'role_key' => 'dept_manager',
                'label_ar' => 'مدير القسم',
                'label_en' => 'Department Manager',
                'is_admin_role' => false,
                'can_manage_members' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_all' => true,
                'sort_order' => 10,
                'permissions' => [
                    Capability::DEPARTMENTS_VIEW,
                    Capability::PROJECTS_VIEW, Capability::PROJECTS_CREATE, Capability::PROJECTS_EDIT,
                    Capability::PROJECTS_DELETE,
                    Capability::PROJECTS_ASSIGN_ROLES,
                    Capability::TASKS_VIEW, Capability::TASKS_CREATE, Capability::TASKS_EDIT,
                    Capability::TASKS_DELETE, Capability::TASKS_COMPLETE, Capability::TASKS_ASSIGN,
                    // Risks (Risk model is ScopeAware -> vertical visibility works)
                    Capability::RISKS_VIEW, Capability::RISKS_CREATE, Capability::RISKS_EDIT,
                    Capability::RISKS_REASSESS, Capability::RISKS_CHANGE_STATUS, Capability::RISKS_VIEW_REPORTS,
                    // OVR (IncidentReport is ScopeAware -> ascends to reporter department)
                    Capability::OVR_VIEW, Capability::OVR_CREATE, Capability::OVR_VIEW_ALL, Capability::OVR_INVESTIGATE,
                    Capability::OVR_CLOSE, Capability::OVR_CHANGE_STATUS, Capability::OVR_ASSIGN,
                    // Phase 5 operational models (all ScopeAware -> department-vertical visibility):
                    // KPIs, Meetings (incl. Decisions/Recommendations via the meeting chain), Surveys.
                    Capability::KPIS_VIEW,
                    Capability::MEETINGS_VIEW,
                    Capability::SURVEYS_VIEW,
                    // Phase 5.1: Strategy view enables department-vertical visibility of
                    // Blockers and Reviews that roll up via their blockable/reviewable
                    // (Project/Program/Task) into the manager's department. The parent
                    // strategy records (Portfolio/Program) keep their own org-scoped roles;
                    // this only grants a department manager read access to strategy records
                    // attached to resources already inside their department subtree.
                    Capability::STRATEGY_VIEW,
                ],
            ],
            [
                'scope_type_id' => $deptScopeId,
                'scope_type_key' => 'department',
                'role_key' => 'dept_member',
                'label_ar' => 'عضو القسم',
                'label_en' => 'Department Member',
                'is_admin_role' => false,
                'can_manage_members' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_all' => true,
                'sort_order' => 20,
                'permissions' => [
                    Capability::DEPARTMENTS_VIEW,
                    Capability::PROJECTS_VIEW, Capability::PROJECTS_CREATE, Capability::PROJECTS_EDIT,
                    Capability::TASKS_VIEW, Capability::TASKS_CREATE, Capability::TASKS_EDIT, Capability::TASKS_COMPLETE,
                    Capability::RISKS_VIEW, Capability::RISKS_CREATE,
                    Capability::OVR_VIEW, Capability::OVR_CREATE,
                ],
            ],
            // Cross-cutting org-scoped roles (horizontal visibility)
            [
                'scope_type_id' => $orgScopeId,
                'scope_type_key' => 'organization',
                'role_key' => 'pmo_manager',
                'label_ar' => 'مدير مكتب المشاريع',
                'label_en' => 'PMO Manager',
                'is_admin_role' => false,
                'can_manage_members' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_all' => true,
                'sort_order' => 40,
                'permissions' => [
                    Capability::PROJECTS_VIEW, Capability::PROJECTS_CREATE, Capability::PROJECTS_EDIT,
                    Capability::PROJECTS_DELETE,
                    Capability::PROJECTS_ASSIGN_ROLES,
                    Capability::TASKS_VIEW, Capability::TASKS_CREATE, Capability::TASKS_EDIT,
                    Capability::TASKS_DELETE, Capability::TASKS_COMPLETE, Capability::TASKS_ASSIGN,
                ],
            ],
            [
                'scope_type_id' => $orgScopeId,
                'scope_type_key' => 'organization',
                'role_key' => 'pmo_coordinator',
                'label_ar' => 'منسّق مكتب المشاريع',
                'label_en' => 'PMO Coordinator',
                'is_admin_role' => false,
                'can_manage_members' => false,
                'can_edit' => true,
                'can_delete' => false,
                'can_view_all' => true,
                'sort_order' => 50,
                'permissions' => [
                    Capability::PROJECTS_VIEW, Capability::PROJECTS_EDIT,
                    Capability::TASKS_VIEW, Capability::TASKS_EDIT,
                ],
            ],
            [
                'scope_type_id' => $orgScopeId,
                'scope_type_key' => 'organization',
                'role_key' => 'quality_manager',
                'label_ar' => 'مدير الجودة',
                'label_en' => 'Quality Manager',
                'is_admin_role' => false,
                'can_manage_members' => false,
                'can_edit' => true,
                'can_delete' => false,
                'can_view_all' => true,
                'sort_order' => 60,
                'permissions' => [
                    Capability::OVR_VIEW, Capability::OVR_VIEW_ALL, Capability::OVR_INVESTIGATE,
                    Capability::OVR_CLOSE, Capability::OVR_CHANGE_STATUS, Capability::OVR_ASSIGN,
                    Capability::OVR_VIEW_STATISTICS, Capability::OVR_EXPORT,
                ],
            ],
            [
                'scope_type_id' => $orgScopeId,
                'scope_type_key' => 'organization',
                'role_key' => 'risk_manager',
                'label_ar' => 'مدير المخاطر',
                'label_en' => 'Risk Manager',
                'is_admin_role' => false,
                'can_manage_members' => false,
                'can_edit' => true,
                'can_delete' => false,
                'can_view_all' => true,
                'sort_order' => 70,
                'permissions' => [
                    Capability::RISKS_VIEW, Capability::RISKS_CREATE, Capability::RISKS_EDIT,
                    Capability::RISKS_REASSESS, Capability::RISKS_CHANGE_STATUS, Capability::RISKS_VIEW_REPORTS,
                ],
            ],
            [
                'scope_type_id' => $orgScopeId,
                'scope_type_key' => 'organization',
                'role_key' => 'cluster_auditor',
                'label_ar' => 'مدقق سجل النشاط على مستوى التجمع',
                'label_en' => 'Cluster Audit Viewer',
                'is_admin_role' => false,
                'can_manage_members' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_all' => false,
                'sort_order' => 80,
                'permissions' => [
                    Capability::AUDIT_VIEW,
                    Capability::AUDIT_EXPORT,
                    Capability::CLUSTER_TREE_VIEW,
                    Capability::CLUSTER_TREE_EXPORT,
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $permissions = $def['permissions'];
            $scopeTypeKey = $def['scope_type_key'];

            // Phase 3 (ADR-UNIFIED-ROLE-ACCESS): the granular can_* flags are retired
            // as columns. Their grants are expressed directly in permissions[] — expand
            // each flag to the exact capability family the engine used to derive from it,
            // then drop the flag keys so forceFill does not touch missing columns.
            // is_admin_role is KEPT as the engine's all-capabilities shortcut.
            $permissions = array_values(array_unique(array_merge(
                $permissions,
                $this->expandGranularFlags($def)
            )));
            unset(
                $def['permissions'], $def['scope_type_key'],
                $def['can_manage_members'], $def['can_edit'],
                $def['can_delete'], $def['can_view_all'], $def['can_view_confidential']
            );

            // firstOrNew on the unique key (scope_type_id + role_key) keeps the insert
            // idempotent without resetting created_at on re-run.
            $definition = ScopedRoleDefinition::firstOrNew([
                'scope_type_id' => $def['scope_type_id'],
                'role_key' => $def['role_key'],
            ]);

            // forceFill covers both the model attributes and the legacy NOT NULL columns
            // (name / display_name / scope_type) that are not on the model's $fillable but
            // are required by the original 2026_01_12 schema.
            $definition->forceFill(array_merge($def, [
                'permissions' => $permissions,
                'is_active' => true,
                'name' => $scopeTypeKey.'.'.$def['role_key'],
                'display_name' => $def['label_ar'],
                'scope_type' => $scopeTypeKey,
            ]))->save();
        }
    }

    /**
     * Expand a definition's granular can_* flags to the capabilities they grant.
     * Mirrors the retired AccessDecision::capabilityMatchesFlags: the write/read/
     * member flags matched by action suffix across ALL modules, and can_view_confidential
     * maps to the single ovr.view_confidential capability.
     *
     * @param  array<string, mixed>  $def
     * @return array<int, string>
     */
    private function expandGranularFlags(array $def): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $out = [];
        if (! empty($def['can_edit'])) {
            $out = array_merge($out, $byAction(['edit', 'update']));
        }
        if (! empty($def['can_delete'])) {
            $out = array_merge($out, $byAction(['delete', 'remove']));
        }
        if (! empty($def['can_view_all'])) {
            $out = array_merge($out, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($def['can_manage_members'])) {
            $out = array_merge($out, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($def['can_view_confidential'])) {
            $out[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return $out;
    }
}
