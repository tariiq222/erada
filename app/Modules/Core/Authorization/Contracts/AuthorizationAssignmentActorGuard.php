<?php

namespace App\Modules\Core\Authorization\Contracts;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\User;

interface AuthorizationAssignmentActorGuard
{
    public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool;
}
