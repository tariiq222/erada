<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Database\Eloquent\Model;

final class AssignmentScopeResolver
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'department' => Department::class,
        'project' => Project::class,
        'program' => Program::class,
        'portfolio' => Portfolio::class,
        'kpi' => Kpi::class,
        'meeting' => Meeting::class,
        'survey' => Survey::class,
    ];

    public function organizationId(AssignmentScope $scope, User $subject): ?int
    {
        if ($scope->type === 'all') {
            return null;
        }

        if ($scope->type === 'own') {
            return $this->requireSubjectOrganization($subject);
        }

        if ($scope->type === 'organization') {
            $organization = Organization::query()->find($scope->id);
            if ($organization === null) {
                throw new AuthorizationAssignmentDenied('The requested organization scope does not exist.');
            }

            return $this->assertSubjectOrganization($subject, (int) $organization->id);
        }

        $modelClass = self::MODELS[$scope->type] ?? null;
        if ($modelClass === null) {
            throw new AuthorizationAssignmentDenied("Scope type [{$scope->type}] cannot be safely resolved yet.");
        }

        $target = $modelClass::query()->find($scope->id);
        if (! $target instanceof ScopeAware) {
            throw new AuthorizationAssignmentDenied('The requested scope does not exist or lacks an organization contract.');
        }

        $organizationId = $target->scopeOrganizationId();
        if ($organizationId === null) {
            throw new AuthorizationAssignmentDenied('The requested scope has no resolvable organization.');
        }

        return $this->assertSubjectOrganization($subject, $organizationId);
    }

    public function target(AssignmentScope $scope, User $subject): ?Model
    {
        if ($scope->type === 'all') {
            return null;
        }

        if ($scope->type === 'own') {
            return $subject;
        }

        if ($scope->type === 'organization') {
            return $subject;
        }

        $modelClass = self::MODELS[$scope->type] ?? null;

        return $modelClass === null ? null : $modelClass::query()->find($scope->id);
    }

    private function requireSubjectOrganization(User $subject): int
    {
        if ($subject->organization_id === null) {
            throw new AuthorizationAssignmentDenied('The subject has no organization context.');
        }

        return (int) $subject->organization_id;
    }

    private function assertSubjectOrganization(User $subject, int $organizationId): int
    {
        if ($this->requireSubjectOrganization($subject) !== $organizationId) {
            throw new AuthorizationAssignmentDenied('Cross-organization role assignments are forbidden.');
        }

        return $organizationId;
    }
}
