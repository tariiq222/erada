<?php

namespace App\Console\Commands;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Services\AssignmentScopeResolver;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only, fail-closed release gate for the canonical authorization cutover.
 */
class AuthzCutoverPreflightCommand extends Command
{
    protected $signature = 'authz:cutover-preflight';

    protected $description = 'Verify that canonical authorization routes, code, data, seed catalog, and runtime configuration are release-ready.';

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'authorization_resources',
        'authorization_roles',
        'authorization_role_permissions',
        'authorization_role_assignments',
    ];

    /** @var list<string> */
    private const NULL_SCOPE_TYPES = ['all', 'own'];

    /**
     * Declared scope_type values accepted by StoreRoleRequest / UpdateRoleRequest
     * AND enforced at the database level by migration
     * `2026_07_12_000013_restrict_authorization_role_scopes`. The CHECK
     * constraint is authoritative — this list is duplicated here so the
     * preflight can cheaply surface any pre-existing malformed rows on
     * databases that have not yet been migrated to the new constraint.
     *
     * MUST mirror AssignmentScope::TYPES exactly: roles and assignments both
     * require exact scope_type match, so the role table must accept the full
     * supported set (including `own`, which is assignment-shape but also a
     * legitimate role declaration shape).
     *
     * @var list<string>
     */
    private const ROLE_DEFINITION_SCOPES = [
        'all',
        'organization',
        'department',
        'own',
        'project',
        'program',
        'portfolio',
        'kpi',
        'meeting',
        'survey',
    ];

    public function handle(): int
    {
        $checks = [
            'route middleware inventory' => fn (): array => $this->routeMiddlewareInventory(),
            'production authorization callsites' => fn (): array => $this->productionCallsites(),
            'canonical row integrity' => fn (): array => $this->canonicalIntegrity(),
            'canonical integrity report' => fn (): array => $this->canonicalIntegrityReport(),
            'fresh-install seed readiness' => fn (): array => $this->seedReadiness(),
            'canonical-only runtime configuration' => fn (): array => $this->canonicalRuntimeConfiguration(),
        ];

        $ready = true;
        foreach ($checks as $label => $check) {
            try {
                [$passed, $details] = $check();
            } catch (Throwable $exception) {
                $passed = false;
                $details = ['check_error='.$exception->getMessage()];
            }

            $ready = $ready && $passed;
            $this->line(sprintf('[%s] %s', $passed ? 'PASS' : 'FAIL', $label));
            foreach ($details as $detail) {
                $this->line('  '.$detail);
            }
        }

        if (! $ready) {
            $this->error('NOT READY');

            return self::FAILURE;
        }

        // This is intentionally the only successful terminal verdict. Release
        // automation may match the complete line, not a substring in prose.
        $this->info('READY');

        return self::SUCCESS;
    }

    /** @return array{bool, list<string>} */
    protected function routeMiddlewareInventory(): array
    {
        $legacy = [];

        /** @var LaravelRoute $route */
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $name = is_string($middleware) ? $middleware : $middleware::class;
                if (preg_match('/(^|\\\\)(RoleMiddleware|PermissionMiddleware|RoleOrPermissionMiddleware)$/', $name)
                    || preg_match('/^(role|permission):/', $name)) {
                    $legacy[] = implode('|', $route->methods()).' '.$route->uri().' -> '.$name;
                }
            }
        }

        sort($legacy);

        return [$legacy === [], $legacy === [] ? ['legacy_middleware=0'] : $legacy];
    }

    /** @return array{bool, list<string>} */
    protected function productionCallsites(): array
    {
        $patterns = [
            '/->\s*(hasRole|hasAnyRole|hasAllRoles|hasPermissionTo|hasAnyPermission|canDirectly)\s*\(/',
            '/\bSpatie\\\\Permission\\\\(Models|Middlewares|Middleware)\\\\/',
        ];
        $allowed = [
            base_path('app/Modules/Core/Authorization/AccessDecision.php'),
            base_path('app/Modules/Shared/Formatters/ActivityLogFormatter.php'),
        ];
        $hits = [];

        foreach ([base_path('app'), base_path('routes')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php' || in_array($file->getPathname(), $allowed, true)) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    continue;
                }
                $code = $this->phpWithoutComments($source);
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $code) === 1) {
                        $hits[] = str_replace(base_path().'/', '', $file->getPathname());
                        break;
                    }
                }
            }
        }

        $hits = array_values(array_unique($hits));
        sort($hits);

        return [$hits === [], $hits === [] ? ['legacy_decision_callsites=0'] : $hits];
    }

    /** @return array{bool, list<string>} */
    protected function canonicalIntegrity(): array
    {
        $missingTables = array_values(array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            return [false, ['missing_tables='.implode(',', $missingTables)]];
        }

        $counts = [
            'orphan_users' => DB::table('authorization_role_assignments as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->whereNull('u.id')->count(),
            'orphan_roles' => DB::table('authorization_role_assignments as a')->leftJoin('authorization_roles as r', 'r.id', '=', 'a.authorization_role_id')->whereNull('r.id')->count(),
            'orphan_permission_roles' => DB::table('authorization_role_permissions as p')->leftJoin('authorization_roles as r', 'r.id', '=', 'p.authorization_role_id')->whereNull('r.id')->count(),
            'orphan_resources' => DB::table('authorization_role_permissions as p')->leftJoin('authorization_resources as r', 'r.id', '=', 'p.authorization_resource_id')->whereNull('r.id')->count(),
            'invalid_scope_shape' => DB::table('authorization_role_assignments')
                ->where(fn ($query) => $query
                    ->where(fn ($nested) => $nested->whereIn('scope_type', self::NULL_SCOPE_TYPES)->whereNotNull('scope_id'))
                    ->orWhere(fn ($nested) => $nested->whereNotIn('scope_type', self::NULL_SCOPE_TYPES)->whereNull('scope_id')))
                ->count(),
            'role_scope_mismatches' => DB::table('authorization_role_assignments as a')
                ->join('authorization_roles as r', 'r.id', '=', 'a.authorization_role_id')
                ->whereColumn('a.scope_type', '!=', 'r.scope_type')
                ->count(),
            'malformed_role_scope_types' => DB::table('authorization_roles')
                ->whereNotIn('scope_type', self::ROLE_DEFINITION_SCOPES)
                ->count(),
            'duplicate_semantic_assignments' => DB::query()->fromSub(
                DB::table('authorization_role_assignments')
                    ->selectRaw('authorization_role_id, user_id, scope_type, COALESCE(scope_id, 0) AS semantic_scope_id, COUNT(*) AS aggregate')
                    ->groupBy('authorization_role_id', 'user_id', 'scope_type', DB::raw('COALESCE(scope_id, 0)'))
                    ->havingRaw('COUNT(*) > 1'),
                'duplicates',
            )->count(),
            'unknown_capabilities' => $this->unknownCapabilityCount(),
            'cross_org_rows' => $this->crossOrganizationCount(),
        ];

        $details = [];
        foreach ($counts as $category => $count) {
            $details[] = $category.'='.$count;
        }

        return [array_sum($counts) === 0, $details];
    }

    protected function unknownCapabilityCount(): int
    {
        $known = [];
        foreach (CapabilityToAuthorizationRolePermission::mapAll() as $mapping) {
            $known[$mapping['resource'].'|'.$mapping['action']] = true;
        }

        return DB::table('authorization_role_permissions as p')
            ->join('authorization_resources as r', 'r.id', '=', 'p.authorization_resource_id')
            ->get(['r.key', 'p.action'])
            ->filter(fn ($row): bool => ! isset($known[$row->key.'|'.$row->action]))
            ->count();
    }

    protected function crossOrganizationCount(): int
    {
        $resolver = app(AssignmentScopeResolver::class);
        $count = 0;

        AuthorizationRoleAssignment::query()->with('user')->orderBy('id')->chunkById(200, function ($assignments) use ($resolver, &$count): void {
            foreach ($assignments as $assignment) {
                $user = $assignment->user;
                if (! $user instanceof User) {
                    continue;
                }

                try {
                    $organizationId = $resolver->organizationId(
                        new AssignmentScope($assignment->scope_type, $assignment->scope_id, (bool) $assignment->inherit_to_children),
                        $user,
                    );
                    $stored = $assignment->organization_id === null ? null : (int) $assignment->organization_id;
                    if ($stored !== $organizationId) {
                        $count++;
                    }
                } catch (Throwable) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /** @return array{bool, list<string>} */
    protected function canonicalIntegrityReport(): array
    {
        if (! array_key_exists('authz:parity-report', Artisan::all())) {
            return [false, ['command_missing=authz:parity-report']];
        }

        $directory = storage_path('framework/cache');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.'/authz-preflight-parity-'.getmypid().'.json';

        try {
            $exitCode = Artisan::call('authz:parity-report', ['--json' => $path]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return [$exitCode === self::SUCCESS, ['exit_code='.$exitCode]];
    }

    /** @return array{bool, list<string>} */
    protected function seedReadiness(): array
    {
        if (array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! Schema::hasTable($table)) !== []) {
            return [false, ['canonical_tables_missing']];
        }

        $failures = [];
        foreach (RolesAndPermissionsSeeder::roleCatalog() as $name => $definition) {
            $role = DB::table('authorization_roles')->where('name', $name)->first();
            if ($role === null || ! (bool) $role->is_active || (bool) $role->is_admin_role !== $definition['is_admin_role']) {
                $failures[] = 'role='.$name;

                continue;
            }

            foreach ($definition['capabilities'] as $capability) {
                $mapping = CapabilityToAuthorizationRolePermission::map($capability);
                if ($mapping === null) {
                    $failures[] = 'unmapped='.$capability;

                    continue;
                }
                $exists = DB::table('authorization_role_permissions as p')
                    ->join('authorization_resources as r', 'r.id', '=', 'p.authorization_resource_id')
                    ->where('p.authorization_role_id', $role->id)
                    ->where('r.key', $mapping['resource'])
                    ->where('p.action', $mapping['action'])
                    ->exists();
                if (! $exists) {
                    $failures[] = 'missing='.$name.':'.$capability;
                }
            }
        }

        $unmappedCatalog = array_values(array_filter(Capability::all(), fn (string $capability): bool => CapabilityToAuthorizationRolePermission::map($capability) === null));
        foreach ($unmappedCatalog as $capability) {
            $failures[] = 'unmapped_catalog='.$capability;
        }

        return [$failures === [], $failures === [] ? ['catalog_complete=1'] : array_slice($failures, 0, 50)];
    }

    /** @return array{bool, list<string>} */
    protected function canonicalRuntimeConfiguration(): array
    {
        $configFile = base_path('config/authorization.php');
        $source = is_file($configFile) ? file_get_contents($configFile) : false;
        $hasDeprecatedRuntimeMode = config('authorization.runtime_mode') !== null
            || (is_string($source) && (str_contains($source, 'runtime_mode') || str_contains($source, 'AUTHORIZATION_RUNTIME_MODE')));

        return [
            ! $hasDeprecatedRuntimeMode,
            [
                'canonical_engine=only',
                'deprecated_runtime_mode='.(int) $hasDeprecatedRuntimeMode,
            ],
        ];
    }

    private function phpWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
