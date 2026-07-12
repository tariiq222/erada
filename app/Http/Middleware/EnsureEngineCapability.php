<?php

namespace App\Http\Middleware;

use App\Modules\Core\Authorization\AccessDecision;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureEngineCapability - canonical route capability gate.
 *
 * This middleware delegates the decision to AccessDecision::can(), the single
 * source of truth. Capability strings must come from
 * App\Modules\Core\Authorization\Capability constants (e.g. HR_VIEW).
 *
 * Usage:
 *   Route::middleware(['engine_capability:hr.view'])->...
 *   Route::middleware(['engine_capability:'.Capability::HR_VIEW])->...
 *
 * Must be applied AFTER `auth:sanctum` so $request->user() resolves. The
 * Capability engine itself handles super_admin bypass and organization
 * isolation for the caller; null users fall through (auth middleware owns
 * the 401 path).
 */
class EnsureEngineCapability
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $capability  The Capability::* constant value to check.
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        // No authenticated user: let `auth:sanctum` own the 401 response.
        // We never want a 403 for "not logged in" — that is a routing/auth concern.
        if (! $user) {
            return $next($request);
        }

        if (! AccessDecision::can($user, $capability)) {
            return response()->json([
                'message' => 'ليس لديك صلاحية للوصول لهذا المورد',
                'required_capability' => $capability,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
