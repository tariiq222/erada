<?php

namespace App\Console\Commands;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Read-only integrity gate for the canonical authorization graph.
 *
 * The report intentionally contains database identifiers and decision metadata
 * only. Names, email addresses, labels and free-form trace reasons are excluded.
 */
final class AuthzParityReportCommand extends Command
{
    protected $signature = 'authz:parity-report {--json= : Write deterministic JSON to this path}';

    protected $description = 'Report canonical authorization integrity blockers';

    /** @var array<string, list<array<string, mixed>>> */
    private array $categories = [];

    public function handle(): int
    {
        $this->categories = array_fill_keys([
            'orphan',
            'unknown',
            'duplicate',
            'cross_org',
        ], []);

        $this->collectCanonicalIntegrity();

        foreach ($this->categories as &$issues) {
            usort($issues, fn (array $left, array $right): int => strcmp($this->stableKey($left), $this->stableKey($right)));
        }
        unset($issues);

        $counts = array_map('count', $this->categories);
        $report = [
            'schema_version' => 1,
            'summary' => [
                'users_scanned' => Schema::hasTable('users') ? DB::table('users')->count() : 0,
                'capabilities_scanned' => count(array_unique(Capability::all())),
                'issues' => array_sum($counts),
                'by_category' => $counts,
            ],
            'categories' => $this->categories,
        ];

        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (JsonException $exception) {
            $this->error('Unable to encode canonical authorization integrity report: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            $path = (string) $this->option('json');
            $directory = dirname($path);
            File::ensureDirectoryExists($directory);
            if (! is_writable($directory) || file_put_contents($path, $json) === false) {
                $this->error("Unable to write canonical authorization integrity report to {$path}");

                return self::FAILURE;
            }
        } else {
            $this->line($json);
        }

        $issueCount = $report['summary']['issues'];
        $this->components->info("Canonical authorization integrity report: {$issueCount} issue(s).");

        return $issueCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function collectCanonicalIntegrity(): void
    {
        if (! Schema::hasTable('authorization_role_assignments')) {
            $this->add('orphan', ['entity' => 'table', 'key' => 'authorization_role_assignments']);

            return;
        }

        $assignments = DB::table('authorization_role_assignments as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('authorization_roles as r', 'r.id', '=', 'a.authorization_role_id')
            ->select('a.*', 'u.id as resolved_user_id', 'u.organization_id as user_organization_id', 'r.id as resolved_role_id')
            ->orderBy('a.id')->get();

        foreach ($assignments as $assignment) {
            if ($assignment->resolved_user_id === null || $assignment->resolved_role_id === null) {
                $this->add('orphan', [
                    'entity' => 'assignment',
                    'id' => (int) $assignment->id,
                    'missing' => $assignment->resolved_user_id === null ? 'user' : 'role',
                ]);
            }

            $scopeOrganization = $this->scopeOrganizationId((string) $assignment->scope_type, $assignment->scope_id);
            if (! in_array($assignment->scope_type, ['all', 'own'], true) && $scopeOrganization === false) {
                $this->add('orphan', [
                    'entity' => 'assignment_scope',
                    'id' => (int) $assignment->id,
                    'scope_type' => (string) $assignment->scope_type,
                    'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                ]);
            } elseif (is_int($scopeOrganization)
                && ((int) $assignment->user_organization_id !== $scopeOrganization
                    || ($assignment->organization_id !== null && (int) $assignment->organization_id !== $scopeOrganization))) {
                $this->add('cross_org', [
                    'entity' => 'assignment',
                    'id' => (int) $assignment->id,
                    'user_organization_id' => $assignment->user_organization_id === null ? null : (int) $assignment->user_organization_id,
                    'scope_organization_id' => $scopeOrganization,
                ]);
            }
        }

        DB::table('authorization_role_assignments')
            ->selectRaw('MIN(id) as first_id, COUNT(*) as row_count, user_id, authorization_role_id, scope_type, scope_id, source')
            ->groupBy('user_id', 'authorization_role_id', 'scope_type', 'scope_id', 'source')
            ->havingRaw('COUNT(*) > 1')->orderBy('first_id')->get()
            ->each(fn (object $row) => $this->add('duplicate', [
                'entity' => 'assignment',
                'first_id' => (int) $row->first_id,
                'row_count' => (int) $row->row_count,
                'user_id' => (int) $row->user_id,
                'role_id' => (int) $row->authorization_role_id,
                'scope_type' => (string) $row->scope_type,
                'scope_id' => $row->scope_id === null ? null : (int) $row->scope_id,
                'source' => (string) $row->source,
            ]));

        if (Schema::hasTable('authorization_role_permissions') && Schema::hasTable('authorization_resources')) {
            $known = [];
            foreach (CapabilityToAuthorizationRolePermission::mapAll() as $mapping) {
                $known[$mapping['resource'].'|'.$mapping['action']] = true;
            }

            DB::table('authorization_role_permissions as p')
                ->leftJoin('authorization_resources as r', 'r.id', '=', 'p.authorization_resource_id')
                ->select('p.authorization_role_id', 'p.authorization_resource_id', 'p.action', 'r.key')
                ->orderBy('p.authorization_role_id')->orderBy('p.authorization_resource_id')->orderBy('p.action')
                ->get()->each(function (object $row) use ($known): void {
                    if ($row->key === null) {
                        $this->add('orphan', [
                            'entity' => 'role_permission',
                            'role_id' => (int) $row->authorization_role_id,
                            'resource_id' => (int) $row->authorization_resource_id,
                            'action' => (string) $row->action,
                        ]);
                    } elseif (! isset($known[$row->key.'|'.$row->action])) {
                        $this->add('unknown', [
                            'entity' => 'role_permission',
                            'role_id' => (int) $row->authorization_role_id,
                            'resource_key' => (string) $row->key,
                            'action' => (string) $row->action,
                        ]);
                    }
                });
        }
    }

    private function scopeOrganizationId(string $scopeType, mixed $scopeId): int|false|null
    {
        if ($scopeType === 'all' || $scopeType === 'own') {
            return null;
        }

        $table = [
            'organization' => 'organizations',
            'department' => 'departments',
            'project' => 'projects',
            'program' => 'programs',
            'portfolio' => 'portfolios',
            'meeting' => 'meetings',
            'survey' => 'surveys',
            'kpi' => 'kpis',
        ][$scopeType] ?? null;

        if ($table === null || $scopeId === null || ! Schema::hasTable($table)) {
            return false;
        }
        if ($scopeType === 'organization') {
            return DB::table($table)->where('id', $scopeId)->exists() ? (int) $scopeId : false;
        }
        if (! Schema::hasColumn($table, 'organization_id')) {
            return false;
        }

        $organizationId = DB::table($table)->where('id', $scopeId)->value('organization_id');

        return $organizationId === null ? false : (int) $organizationId;
    }

    /** @param array<string, mixed> $issue */
    private function add(string $category, array $issue): void
    {
        $this->categories[$category][] = $issue;
    }

    /** @param array<string, mixed> $issue */
    private function stableKey(array $issue): string
    {
        ksort($issue);

        return json_encode($issue, JSON_UNESCAPED_SLASHES) ?: '';
    }
}
