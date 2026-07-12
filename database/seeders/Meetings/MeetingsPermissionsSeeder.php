<?php

namespace Database\Seeders\Meetings;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MeetingsPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private const CAPABILITIES = [
        Capability::MEETINGS_VIEW,
        Capability::MEETINGS_CREATE,
        Capability::MEETINGS_EDIT,
        Capability::MEETINGS_DELETE,
        Capability::MEETINGS_RECORD_DECISIONS,
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = AuthorizationRole::query()->updateOrCreate(
                ['name' => 'admin'],
                [
                    'label' => 'Organization Admin',
                    'is_admin_role' => true,
                    'is_active' => true,
                ],
            );

            foreach (self::CAPABILITIES as $capability) {
                $mapping = CapabilityToAuthorizationRolePermission::map($capability);
                if ($mapping === null) {
                    continue;
                }

                $resource = AuthorizationResource::query()->updateOrCreate(
                    ['key' => $mapping['resource']],
                    ['label' => class_basename($mapping['resource'])],
                );

                DB::table('authorization_role_permissions')->updateOrInsert(
                    [
                        'authorization_role_id' => $admin->id,
                        'authorization_resource_id' => $resource->id,
                        'action' => $mapping['action'],
                    ],
                    ['reach' => null],
                );
            }
        });

        AccessDecision::flushCache();
        $this->command?->info('Canonical meeting permissions seeded successfully.');
    }
}
