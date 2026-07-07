<?php

namespace App\Modules\Core\Authorization\Support;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;

/**
 * CapabilityToAuthorizationRolePermission -- Phase 1 Task 1.2.1.
 *
 * Pure mapping from a legacy `Capability` constant (a flat `module.action`
 * string) to the (resource FQCN, action suffix) pair that the new
 * `authorization_role_permissions` pivot stores.
 *
 * The mapping has TWO sources:
 *   1. The approved defaults documented by the user / plan:
 *        - strategy.*      -> App\Modules\Strategy\Models\Portfolio
 *        - hr.*            -> App\Modules\HR\Models\Department
 *        - attachments.*   -> App\Modules\Shared\Models\Attachment
 *        - comments.*      -> App\Modules\Shared\Models\Comment
 *   2. The nearest existing canonical model for every other prefix,
 *      resolved from the model's own location under `app/Modules/<Module>/Models/`.
 *
 * The class is read-only. It performs no DB I/O and contains no side-effects.
 * That keeps the mapper trivially testable and lets the artisan seeder
 * command own the only place where it touches the database.
 */
final class CapabilityToAuthorizationRolePermission
{
    /**
     * Per-prefix FQCN table -- the canonical mapping for each top-level
     * Capability prefix to a real, existing model class.
     *
     * The four user-approved defaults appear first so an intentional override
     * is visible at a glance. The remaining prefixes follow the existing
     * module -> nearest-model convention.
     *
     * @var array<string, class-string>
     */
    private const PREFIX_TO_RESOURCE = [
        // ---- User-approved defaults (plan section 1.2.1) ----
        'strategy' => Portfolio::class,
        'hr' => Department::class,
        'attachments' => Attachment::class,
        'comments' => Comment::class,

        // ---- Nearest-existing-model for every other prefix ----
        'projects' => Project::class,
        'tasks' => Task::class,
        'departments' => Department::class,
        'meetings' => Meeting::class,
        'recommendations' => Recommendation::class,
        'ovr' => IncidentReport::class,
        'risks' => Risk::class,
        'surveys' => Survey::class,
        'kpis' => Kpi::class,
        'users' => User::class,
        'settings' => SystemSettings::class,
        'audit' => ActivityLog::class,
        'core' => Organization::class,
        'roles' => AuthorizationRole::class,
        // Phase 8-C: dashboards are organization-scoped; map to the
        // Organization resource (the closest existing FQCN — the
        // dashboard is an org-wide view, not a per-record resource).
        'dashboard' => Organization::class,
    ];

    /**
     * Resolve a Capability string to its (resource, action) seed-map pair.
     *
     * @return array{resource: class-string, action: string}|null
     */
    public static function map(string $capability): ?array
    {
        if ($capability === '') {
            return null;
        }

        $dotPosition = strrpos($capability, '.');

        if ($dotPosition === false) {
            // Capability strings without a module.action separator cannot be
            // resolved into a (resource, action) pair -- the action suffix
            // rule expects at least one dot.
            return null;
        }

        $prefix = substr($capability, 0, $dotPosition);
        $action = substr($capability, $dotPosition + 1);

        if ($action === '' || ! array_key_exists($prefix, self::PREFIX_TO_RESOURCE)) {
            return null;
        }

        return [
            'resource' => self::PREFIX_TO_RESOURCE[$prefix],
            'action' => $action,
        ];
    }

    /**
     * The single target role this first pass seeds `authorization_role_permissions`
     * for. Phase 2 / backfill will introduce additional roles; this constant
     * is intentionally the only seed entry point so the migration is
     * additive and reversible.
     */
    public const SEED_ROLE_NAME = 'super_admin';

    public const SEED_ROLE_LABEL = 'Super Admin';

    /**
     * Convenience accessor for the per-prefix mapping table -- the artisan
     * command reads this to print a preview without re-implementing the
     * resource lookup.
     *
     * @return array<string, class-string>
     */
    public static function prefixMap(): array
    {
        return self::PREFIX_TO_RESOURCE;
    }

    /**
     * Resolve every Capability::all() entry into a (resource, action, capability)
     * triple. Used by the artisan seeder for the --apply + --dry-run paths.
     *
     * @return list<array{capability: string, resource: class-string, action: string}>
     */
    public static function mapAll(): array
    {
        $rows = [];
        foreach (Capability::all() as $capability) {
            $row = self::map($capability);
            if ($row === null) {
                continue;
            }
            $rows[] = [
                'capability' => $capability,
                'resource' => $row['resource'],
                'action' => $row['action'],
            ];
        }

        return $rows;
    }
}
