<?php

namespace App\Modules\Core\Authorization\Data;

use App\Modules\Core\Authorization\Models\AuthorizationRole;

final readonly class RoleAssignmentWrite
{
    public function __construct(
        public AuthorizationRole $role,
        public AssignmentWrite $assignment,
    ) {}
}
