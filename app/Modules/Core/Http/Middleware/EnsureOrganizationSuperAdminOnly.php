<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 7 route gate).
 *
 * Genuine OrgSuper-only guard. Runs BEFORE the actor guard and the service
 * so a non-pure OrgSuper actor is rejected at the middleware layer:
 *   - super_admin is rejected even if they hold Capability::ROLES_ASSIGN.
 *   - OrgSuper with null organization_id is rejected (cannot derive scope).
 *   - Any actor that is not organization_super_admin is rejected.
 *
 * The middleware returns 403 (not 422) because the request is well-formed
 * but the actor lacks the route-level capability; the FormRequest layer
 * (422 for payload-shape violations) and the actor guard (403 for
 * per-assignment denials) are defense-in-depth below this gate.
 */
final class EnsureOrganizationSuperAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($actor->isSuperAdmin()) {
            return response()->json([
                'message' => 'Platform Super Admin cannot use the OrgSuper role-assignment route.',
            ], 403);
        }

        if (! $actor->isOrganizationSuperAdmin()) {
            return response()->json([
                'message' => 'Only Organization Super Admin can use this route.',
            ], 403);
        }

        if ($actor->organization_id === null) {
            return response()->json([
                'message' => 'Organization Super Admin actor has no organization context.',
            ], 403);
        }

        return $next($request);
    }
}
