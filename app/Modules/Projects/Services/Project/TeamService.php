<?php

namespace App\Modules\Projects\Services\Project;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

    /** @param array<int, array<string, mixed>> $teamMembers */
    public function replaceTeamMembers(Project $project, array $teamMembers): void
    {
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
