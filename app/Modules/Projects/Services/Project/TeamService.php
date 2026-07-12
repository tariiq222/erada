<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class TeamService
{
    /** @var array<string, string> */
    protected const ROLE_MAPPING = [
        'مطور' => 'member',
        'محلل' => 'member',
        'مصمم' => 'member',
        'مختبر' => 'member',
        'قائد فريق' => 'manager',
        'مدير' => 'manager',
        'عضو' => 'member',
        'member' => 'member',
        'manager' => 'manager',
        'viewer' => 'viewer',
        'project_member' => 'member',
        'project_manager' => 'manager',
        'project_viewer' => 'viewer',
    ];

    public function __construct(
        private readonly AuthorizationAssignmentActorGuard $assignmentActorGuard,
    ) {}

    public static function resolveRoleConstant(string $rawRole): string
    {
        return self::canonicalRoleName($rawRole);
    }

    /**
     * Project create/update derives these assignments from the project aggregate.
     * They are system-owned (`auto`) and therefore deliberately do not impersonate
     * the initiating user or bypass the manual assignment actor guard.
     *
     * @param  array<int, array<string, mixed>>  $teamMembers
     */
    public function createTeamMembers(Project $project, array $teamMembers): void
    {
        DB::transaction(function () use ($project, $teamMembers): void {
            foreach ($teamMembers as $memberData) {
                $userId = $memberData['user_id'] ?? null;
                if ($userId && $this->isMember($project, (int) $userId)) {
                    continue;
                }

                $this->addMember($project, $memberData);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function addMember(Project $project, array $data): bool
    {
        $userId = $data['user_id'] ?? null;
        if (empty($userId)) {
            return false;
        }

        return DB::transaction(function () use ($project, $data, $userId): bool {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if ($user === null) {
                return false;
            }

            if ($this->isMember($project, (int) $userId)) {
                throw new ProjectMemberAlreadyExistsException;
            }

            $this->syncAutomaticProjectRole($project, $user, self::canonicalRoleName((string) ($data['role'] ?? 'member')));

            return true;
        });
    }

    /**
     * Replace the project's team membership.
     *
     * Defense-in-depth (CSD-CA23078-PROJECTS-002): the bulk project update path
     * used to grant the actor themselves a project role with source=auto, fully
     * bypassing the canonical manual-assignment actor guard. Even though the
     * request-layer validation now rejects a self-grant with role != viewer,
     * a direct service call (CLI, jobs, internal callers) could still trigger
     * it. Before persisting anything we strip every self-assignment entry that
     * would escalate the actor above `project_viewer` unless the canonical actor
     * guard allows the manual equivalent. Blocked entries are logged (with full
     * context) and skipped — the rest of the payload is processed normally so
     * unrelated updates are not aborted.
     *
     * @param  array<int, array<string, mixed>>  $teamMembers
     */
    public function replaceTeamMembers(Project $project, array $teamMembers): void
    {
        $actor = Auth::user();
        $teamMembers = $this->filterSelfAssignmentEscalations($project, $teamMembers, $actor);

        DB::transaction(function () use ($project, $teamMembers): void {
            $protectedRoleId = AuthorizationRole::query()
                ->where('name', 'project_manager')
                ->value('id');

            AuthorizationRoleAssignment::query()
                ->where('scope_type', 'project')
                ->where('scope_id', $project->id)
                ->where('source', 'auto')
                ->when($protectedRoleId !== null, fn ($query) => $query->where('authorization_role_id', '!=', $protectedRoleId))
                ->delete();

            $this->createTeamMembers($project, $teamMembers);
            DB::afterCommit(static fn () => AccessDecision::flushCache());
        });
    }

    /**
     * Strip self-assignment entries whose canonical role would escalate the
     * actor above `project_viewer` without passing the canonical manual-
     * assignment actor guard. Blocked entries are recorded via Log::warning so
     * security reviews can audit the bulk-path bypass attempts.
     *
     * @param  array<int, array<string, mixed>>  $teamMembers
     * @return array<int, array<string, mixed>>
     */
    private function filterSelfAssignmentEscalations(Project $project, array $teamMembers, ?User $actor): array
    {
        if ($actor === null) {
            return $teamMembers;
        }

        $actorId = (int) $actor->id;
        $scope = new AssignmentScope('project', (int) $project->id, false);

        return array_values(array_filter($teamMembers, function (array $entry) use ($actor, $actorId, $project, $scope): bool {
            $entryUserId = isset($entry['user_id']) ? (int) $entry['user_id'] : null;

            if ($entryUserId === null || $entryUserId !== $actorId) {
                return true;
            }

            try {
                $canonicalRoleName = self::canonicalRoleName((string) ($entry['role'] ?? 'member'));
            } catch (InvalidArgumentException) {
                Log::warning('TeamService.replaceTeamMembers: self-assignment skipped — unknown role alias', [
                    'project_id' => $project->id,
                    'actor_id' => $actor->id,
                    'raw_role' => $entry['role'] ?? null,
                    'reason' => 'unknown_role_alias',
                ]);

                return false;
            }

            // Viewer self-assignment is harmless — it is the lowest-privilege
            // project-scope role and the actor already cleared the project's
            // basic edit gate (otherwise they would not be in this method).
            if ($canonicalRoleName === 'project_viewer') {
                return true;
            }

            $role = AuthorizationRole::query()->where('name', $canonicalRoleName)->first();
            if ($role === null) {
                Log::warning('TeamService.replaceTeamMembers: self-assignment skipped — canonical role not found', [
                    'project_id' => $project->id,
                    'actor_id' => $actor->id,
                    'canonical_role' => $canonicalRoleName,
                    'reason' => 'canonical_role_missing',
                ]);

                return false;
            }

            $subject = User::query()->whereKey($actorId)->first();
            if ($subject === null) {
                return false;
            }

            if ($this->assignmentActorGuard->allows($actor, $subject, $role, $scope)) {
                return true;
            }

            Log::warning('TeamService.replaceTeamMembers: self-assignment blocked by canonical actor guard', [
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'canonical_role' => $canonicalRoleName,
                'scope' => $scope->semanticKey(),
                'reason' => 'canonical_assignment_actor_guard_denied',
            ]);

            return false;
        }));
    }

    public function updateMemberRole(Project $project, int $userId, string $role): bool
    {
        return DB::transaction(function () use ($project, $userId, $role): bool {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if ($user === null || ! $this->isMember($project, $userId)) {
                return false;
            }

            $this->syncAutomaticProjectRole($project, $user, self::canonicalRoleName($role));

            return true;
        });
    }

    public function removeMember(Project $project, int $userId): bool
    {
        return DB::transaction(function () use ($project, $userId): bool {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            $deleted = AuthorizationRoleAssignment::query()
                ->where('user_id', $userId)
                ->where('scope_type', 'project')
                ->where('scope_id', $project->id)
                ->where('source', 'auto')
                ->delete() > 0;

            if ($deleted) {
                DB::afterCommit(static fn () => AccessDecision::flushUserCache($userId));
            }

            return $deleted;
        });
    }

    public function getMembersByRole(Project $project, string $role): Collection
    {
        $roleName = self::canonicalRoleName($role);

        return User::query()
            ->whereIn('id', $this->assignmentQuery($project)->whereHas('role', fn ($query) => $query->where('name', $roleName))->select('user_id'))
            ->get();
    }

    public function getMembersCount(Project $project): int
    {
        return $this->assignmentQuery($project)->distinct()->count('user_id');
    }

    public function isMember(Project $project, int $userId): bool
    {
        return $this->assignmentQuery($project)->where('user_id', $userId)->exists();
    }

    public function assignAutomaticManager(Project $project, User $manager): void
    {
        DB::transaction(fn () => $this->syncAutomaticProjectRole($project, $manager, 'project_manager'));
    }

    private function syncAutomaticProjectRole(Project $project, User $user, string $roleName): void
    {
        if ($project->organization_id === null
            || $user->organization_id === null
            || (int) $project->organization_id !== (int) $user->organization_id) {
            throw new RuntimeException('Automatic project roles cannot cross organization boundaries.');
        }

        $role = AuthorizationRole::query()
            ->where('name', $roleName)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if ($role === null) {
            throw new RuntimeException("Missing active canonical project role [{$roleName}].");
        }

        AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where('source', 'auto')
            ->where('authorization_role_id', '!=', $role->id)
            ->delete();

        $identity = [
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
        ];

        if (! AuthorizationRoleAssignment::query()->where($identity)->exists()) {
            AuthorizationRoleAssignment::query()->create($identity + [
                'organization_id' => $project->organization_id,
                'inherit_to_children' => false,
                'expires_at' => null,
                'source' => 'auto',
                'granted_by' => null,
            ]);
        }

        DB::afterCommit(static fn () => AccessDecision::flushUserCache((int) $user->id));
    }

    private function assignmentQuery(Project $project)
    {
        return AuthorizationRoleAssignment::query()
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('role', fn ($query) => $query->where('is_active', true));
    }

    private static function canonicalRoleName(string $rawRole): string
    {
        $mapped = self::ROLE_MAPPING[$rawRole] ?? null;
        if ($mapped === null) {
            throw new InvalidArgumentException("دور غير معروف: {$rawRole}");
        }

        return 'project_'.$mapped;
    }
}
