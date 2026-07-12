<?php

namespace Database\Seeders;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Canonical install-time role catalog.
     *
     * @return array<string, array{label: string, label_ar?: string, label_en?: string, scope_type: string, is_admin_role: bool, capabilities: list<string>}>
     */
    public static function roleCatalog(): array
    {
        $projectManager = [
            Capability::PROJECTS_VIEW,
            Capability::PROJECTS_EDIT,
            Capability::PROJECTS_ASSIGN_ROLES,
            Capability::TASKS_VIEW,
            Capability::TASKS_CREATE,
            Capability::TASKS_EDIT,
            Capability::TASKS_COMPLETE,
            Capability::TASKS_ASSIGN,
            Capability::ATTACHMENTS_VIEW,
            Capability::ATTACHMENTS_UPLOAD,
            Capability::COMMENTS_VIEW,
            Capability::COMMENTS_CREATE,
            Capability::COMMENTS_EDIT,
        ];

        $projectMember = [
            Capability::PROJECTS_VIEW,
            Capability::TASKS_VIEW,
            Capability::TASKS_CREATE,
            Capability::TASKS_EDIT,
            Capability::TASKS_COMPLETE,
            Capability::ATTACHMENTS_VIEW,
            Capability::ATTACHMENTS_UPLOAD,
            Capability::COMMENTS_VIEW,
            Capability::COMMENTS_CREATE,
        ];

        $projectViewer = [
            Capability::PROJECTS_VIEW,
            Capability::TASKS_VIEW,
            Capability::ATTACHMENTS_VIEW,
            Capability::COMMENTS_VIEW,
        ];

        $departmentManager = [
            Capability::DEPARTMENTS_VIEW,
            Capability::DEPARTMENTS_MANAGE_MEMBERS,
            Capability::DEPARTMENTS_ASSIGN_ROLES,
            Capability::PROJECTS_VIEW,
            Capability::PROJECTS_CREATE,
            Capability::PROJECTS_EDIT,
            Capability::PROJECTS_DELETE,
            Capability::PROJECTS_ASSIGN_ROLES,
            Capability::TASKS_VIEW,
            Capability::TASKS_CREATE,
            Capability::TASKS_EDIT,
            Capability::TASKS_DELETE,
            Capability::TASKS_COMPLETE,
            Capability::TASKS_ASSIGN,
            Capability::RISKS_VIEW,
            Capability::RISKS_CREATE,
            Capability::RISKS_EDIT,
            Capability::RISKS_REASSESS,
            Capability::RISKS_CHANGE_STATUS,
            Capability::RISKS_VIEW_REPORTS,
            Capability::OVR_VIEW,
            Capability::OVR_CREATE,
            Capability::OVR_VIEW_ALL,
            Capability::OVR_INVESTIGATE,
            Capability::OVR_CLOSE,
            Capability::OVR_CHANGE_STATUS,
            Capability::OVR_ASSIGN,
            Capability::KPIS_VIEW,
            Capability::MEETINGS_VIEW,
            Capability::SURVEYS_VIEW,
            Capability::STRATEGY_VIEW,
        ];

        return [
            'super_admin' => [
                'label' => 'Super Admin',
                'scope_type' => 'all',
                'is_admin_role' => true,
                'capabilities' => Capability::all(),
            ],
            'admin' => [
                'label' => 'Organization Admin',
                'scope_type' => 'organization',
                'is_admin_role' => true,
                'capabilities' => Capability::all(),
            ],
            'viewer' => [
                'label' => 'Viewer',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => self::viewCapabilities(),
            ],
            'manager' => [
                'label' => 'Manager',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => $projectManager,
            ],
            'member' => [
                'label' => 'Member',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => $projectMember,
            ],
            'project_manager' => [
                'label' => 'Project Manager',
                'scope_type' => 'project',
                'is_admin_role' => false,
                'capabilities' => $projectManager,
            ],
            'project_member' => [
                'label' => 'Project Member',
                'scope_type' => 'project',
                'is_admin_role' => false,
                'capabilities' => $projectMember,
            ],
            'project_viewer' => [
                'label' => 'Project Viewer',
                'scope_type' => 'project',
                'is_admin_role' => false,
                'capabilities' => $projectViewer,
            ],
            'dept_manager' => [
                'label' => 'Department Manager',
                'label_ar' => 'مدير القسم',
                'label_en' => 'Department Manager',
                'scope_type' => 'department',
                'is_admin_role' => false,
                'capabilities' => $departmentManager,
            ],
            'dept_member' => [
                'label' => 'Department Member',
                'label_ar' => 'عضو القسم',
                'label_en' => 'Department Member',
                'scope_type' => 'department',
                'is_admin_role' => false,
                'capabilities' => [
                    Capability::DEPARTMENTS_VIEW,
                    Capability::PROJECTS_VIEW,
                    Capability::PROJECTS_CREATE,
                    Capability::PROJECTS_EDIT,
                    Capability::TASKS_VIEW,
                    Capability::TASKS_CREATE,
                    Capability::TASKS_EDIT,
                    Capability::TASKS_COMPLETE,
                    Capability::RISKS_VIEW,
                    Capability::RISKS_CREATE,
                    Capability::OVR_VIEW,
                    Capability::OVR_CREATE,
                ],
            ],
            'pmo_manager' => [
                'label' => 'PMO Manager',
                'label_ar' => 'مدير مكتب المشاريع',
                'label_en' => 'PMO Manager',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => $projectManager,
            ],
            'pmo_coordinator' => [
                'label' => 'PMO Coordinator',
                'label_ar' => 'منسّق مكتب المشاريع',
                'label_en' => 'PMO Coordinator',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => [
                    Capability::PROJECTS_VIEW,
                    Capability::PROJECTS_EDIT,
                    Capability::TASKS_VIEW,
                    Capability::TASKS_EDIT,
                ],
            ],
            'quality_manager' => [
                'label' => 'Quality Manager',
                'label_ar' => 'مدير الجودة',
                'label_en' => 'Quality Manager',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => [
                    Capability::OVR_VIEW,
                    Capability::OVR_VIEW_ALL,
                    Capability::OVR_INVESTIGATE,
                    Capability::OVR_CLOSE,
                    Capability::OVR_CHANGE_STATUS,
                    Capability::OVR_ASSIGN,
                    Capability::OVR_VIEW_STATISTICS,
                    Capability::OVR_EXPORT,
                ],
            ],
            'risk_manager' => [
                'label' => 'Risk Manager',
                'label_ar' => 'مدير المخاطر',
                'label_en' => 'Risk Manager',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => [
                    Capability::RISKS_VIEW,
                    Capability::RISKS_CREATE,
                    Capability::RISKS_EDIT,
                    Capability::RISKS_REASSESS,
                    Capability::RISKS_CHANGE_STATUS,
                    Capability::RISKS_VIEW_REPORTS,
                ],
            ],
            'cluster_auditor' => [
                'label' => 'Cluster Audit Viewer',
                'label_ar' => 'مدقق سجل النشاط على مستوى التجمع',
                'label_en' => 'Cluster Audit Viewer',
                'scope_type' => 'organization',
                'is_admin_role' => false,
                'capabilities' => [
                    Capability::AUDIT_VIEW,
                    Capability::AUDIT_EXPORT,
                    Capability::CLUSTER_TREE_VIEW,
                    Capability::CLUSTER_TREE_EXPORT,
                ],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = self::roleCatalog();
            $mappings = $this->mappedCapabilities($catalog);

            foreach ($mappings as $mapping) {
                AuthorizationResource::query()->updateOrCreate(
                    ['key' => $mapping['resource']],
                    ['label' => class_basename($mapping['resource'])],
                );
            }

            $resources = AuthorizationResource::query()
                ->whereIn('key', array_column($mappings, 'resource'))
                ->pluck('id', 'key');

            foreach ($catalog as $name => $definition) {
                $role = AuthorizationRole::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'label' => $definition['label'],
                        'label_ar' => $definition['label_ar'] ?? $definition['label'],
                        'label_en' => $definition['label_en'] ?? $definition['label'],
                        'scope_type' => $definition['scope_type'],
                        'is_admin_role' => $definition['is_admin_role'],
                        'is_system' => $name === 'super_admin',
                        'is_active' => true,
                    ],
                );

                $desired = [];
                foreach ($definition['capabilities'] as $capability) {
                    $mapping = CapabilityToAuthorizationRolePermission::map($capability);
                    if ($mapping === null) {
                        continue;
                    }

                    $resourceId = $resources[$mapping['resource']] ?? null;
                    if ($resourceId === null) {
                        continue;
                    }

                    $key = $resourceId.'|'.$mapping['action'];
                    $desired[$key] = [
                        'authorization_role_id' => $role->id,
                        'authorization_resource_id' => $resourceId,
                        'action' => $mapping['action'],
                        'reach' => null,
                    ];
                }

                foreach ($desired as $permission) {
                    DB::table('authorization_role_permissions')->updateOrInsert(
                        [
                            'authorization_role_id' => $permission['authorization_role_id'],
                            'authorization_resource_id' => $permission['authorization_resource_id'],
                            'action' => $permission['action'],
                        ],
                        ['reach' => $permission['reach']],
                    );
                }

            }
        });

        AccessDecision::flushCache();
        $this->command?->info('Canonical authorization roles and permissions seeded successfully.');
    }

    /**
     * @param  array<string, array{label: string, is_admin_role: bool, capabilities: list<string>}>  $catalog
     * @return list<array{resource: class-string, action: string}>
     */
    private function mappedCapabilities(array $catalog): array
    {
        $mapped = [];
        foreach ($catalog as $definition) {
            foreach ($definition['capabilities'] as $capability) {
                $mapping = CapabilityToAuthorizationRolePermission::map($capability);
                if ($mapping !== null) {
                    $mapped[$mapping['resource'].'|'.$mapping['action']] = $mapping;
                }
            }
        }

        return array_values($mapped);
    }

    /** @return list<string> */
    private static function viewCapabilities(): array
    {
        return array_values(array_filter(
            Capability::all(),
            static fn (string $capability): bool => in_array(
                substr($capability, strrpos($capability, '.') + 1),
                ['view', 'view_all', 'view_reports'],
                true,
            ),
        ));
    }
}
