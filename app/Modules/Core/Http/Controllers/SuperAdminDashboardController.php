<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdminDashboardController — M1 of the Super Admin System Governance
 * Console (see docs/superpowers/specs/2026-07-03-super-admin-dashboard-proposal.md).
 *
 * Read-mostly KPI / metadata endpoints. Three responsibilities:
 *
 *   GET /api/admin/overview
 *       Aggregated platform-wide counts: active orgs, active users,
 *       login attempts in the last 24h (successful vs. failed),
 *       pending registration requests.
 *
 *   GET /api/admin/security/alerts
 *       Curated alert metadata from login_attempts and activity_logs
 *       (access_denied events). Counts and fingerprint rows only —
 *       NEVER the underlying payload, NEVER user agent blobs in full.
 *
 *   GET /api/admin/audit/recent
 *       Newest-first slice of activity_logs, ordered, capped at 50
 *       by the route-level limit. Sensitive payloads (old_values,
 *       new_values, metadata) are stripped server-side to enforce
 *       data minimization (per PRD 4).
 *
 * Authorization model:
 *   - Routes are wrapped in auth:sanctum + role:super_admin middleware
 *     (the engine already grants super_admin blanket bypass for
 *     engineering capabilities per the unified-authz spec). No new
 *     Capability constant is introduced — super_admin role membership
 *     IS the authorization gate. Per PRD 6 we deliberately avoid
 *     adding a SYSTEM_GOVERNANCE_* capability family in M1.
 */
class SuperAdminDashboardController extends Controller
{
    /**
     * Hard cap for /admin/audit/recent regardless of per_page request.
     * Even if a client asks for 1000 we slice the newest 50 server-side
     * to keep the payload bounded and the trip cheap.
     */
    private const AUDIT_RECENT_HARD_LIMIT = 50;

    /**
     * Default lookback window for security alerts. PRD 3.4 keeps the
     * window short (last 1 hour) because the alert UI is meant to
     * surface recent attempts only; historical failures are visible
     * via /admin/audit/recent.
     */
    private const SECURITY_WINDOW_MINUTES = 60;

    /**
     * Threshold for repeated-failure buckets (PRD 3.4).
     * A single failed login is noise; 3+ in the window is signal.
     */
    private const REPEATED_FAILURE_THRESHOLD = 3;

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isSuperAdmin(), 403, 'لوحة الحوكمة مقتصرة على super_admin.');

        $cutoff24h = now()->subHours(24);

        $orgsActive = Organization::query()->where('is_active', true)->count();
        $orgsTotal = Organization::query()->count();
        $usersActive = User::query()->where('is_active', true)->count();
        $usersTotal = User::query()->count();
        $usersWith2fa = User::query()
            ->where('is_active', true)
            ->whereNotNull('two_factor_confirmed_at')
            ->count();

        $successful24h = LoginAttempt::query()
            ->where('successful', true)
            ->where('attempted_at', '>=', $cutoff24h)
            ->count();
        $failed24h = LoginAttempt::query()
            ->where('successful', false)
            ->where('attempted_at', '>=', $cutoff24h)
            ->count();

        return response()->json([
            'data' => [
                'organizations' => [
                    'active' => $orgsActive,
                    'total' => $orgsTotal,
                ],
                'users' => [
                    'active' => $usersActive,
                    'total' => $usersTotal,
                    'two_factor_coverage' => [
                        'enabled' => $usersWith2fa,
                        'active_users' => $usersActive,
                        'percent' => $usersActive > 0
                            ? round(($usersWith2fa / $usersActive) * 100, 1)
                            : 0,
                    ],
                ],
                'login_attempts' => [
                    'last_24h' => [
                        'successful' => $successful24h,
                        'failed' => $failed24h,
                        'total' => $successful24h + $failed24h,
                    ],
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function securityAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isSuperAdmin(), 403, 'تنبيهات الأمن مقتصرة على super_admin.');

        $windowCutoff = now()->subMinutes(self::SECURITY_WINDOW_MINUTES);

        // Repeated failures by email (account-takeover signal).
        $failedByEmailRows = LoginAttempt::query()
            ->where('successful', false)
            ->where('attempted_at', '>=', $windowCutoff)
            ->groupBy('email')
            ->havingRaw('COUNT(*) >= ?', [self::REPEATED_FAILURE_THRESHOLD])
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->select('email', DB::raw('COUNT(*) AS attempts_count'))
            ->get();

        $failedByEmail = $failedByEmailRows
            ->map(fn ($row) => [
                'email' => $row->email,
                'attempts' => (int) $row->attempts_count,
                'first_attempted_at' => optional(LoginAttempt::query()
                    ->where('email', $row->email)
                    ->where('successful', false)
                    ->where('attempted_at', '>=', $windowCutoff)
                    ->min('attempted_at'))
                    ?->toIso8601String(),
                'last_attempted_at' => optional(LoginAttempt::query()
                    ->where('email', $row->email)
                    ->where('successful', false)
                    ->where('attempted_at', '>=', $windowCutoff)
                    ->max('attempted_at'))
                    ?->toIso8601String(),
            ])
            ->all();

        // Repeated failures by IP (credential-stuffing signal).
        $failedByIpRows = LoginAttempt::query()
            ->where('successful', false)
            ->where('attempted_at', '>=', $windowCutoff)
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) >= ?', [self::REPEATED_FAILURE_THRESHOLD])
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->select('ip_address', DB::raw('COUNT(*) AS attempts_count'))
            ->get();

        $failedByIp = $failedByIpRows
            ->map(fn ($row) => [
                'ip_address' => $row->ip_address,
                'attempts' => (int) $row->attempts_count,
                'distinct_emails' => LoginAttempt::query()
                    ->where('ip_address', $row->ip_address)
                    ->where('successful', false)
                    ->where('attempted_at', '>=', $windowCutoff)
                    ->distinct()
                    ->count('email'),
                'first_attempted_at' => optional(LoginAttempt::query()
                    ->where('ip_address', $row->ip_address)
                    ->where('successful', false)
                    ->where('attempted_at', '>=', $windowCutoff)
                    ->min('attempted_at'))
                    ?->toIso8601String(),
                'last_attempted_at' => optional(LoginAttempt::query()
                    ->where('ip_address', $row->ip_address)
                    ->where('successful', false)
                    ->where('attempted_at', '>=', $windowCutoff)
                    ->max('attempted_at'))
                    ?->toIso8601String(),
            ])
            ->all();

        // Access-denied events (denied elevation signal). Strip noisy
        // payloads; only expose counts, actor/target identities, action.
        $deniedEvents = ActivityLog::query()
            ->where('action', ActivityLog::ACTION_ACCESS_DENIED)
            ->where('created_at', '>=', $windowCutoff)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'user_id', 'action', 'loggable_type', 'ip_address', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'action' => $row->action,
                'route' => $row->loggable_type,
                'ip_address' => $row->ip_address,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'data' => [
                'windows' => [
                    'minutes' => self::SECURITY_WINDOW_MINUTES,
                    'cutoff' => $windowCutoff->toIso8601String(),
                    'repeated_failure_threshold' => self::REPEATED_FAILURE_THRESHOLD,
                ],
                'failed_logins_repeated' => array_merge($failedByEmail, $failedByIp),
                'access_denied_events' => $deniedEvents,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function auditRecent(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isSuperAdmin(), 403, 'سجل التدقيق مقتصر على super_admin.');

        $perPage = (int) $request->query('per_page', self::AUDIT_RECENT_HARD_LIMIT);
        $perPage = max(1, min($perPage, self::AUDIT_RECENT_HARD_LIMIT));
        $page = max(1, (int) $request->query('page', 1));

        $rows = ActivityLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id') // deterministic tie-breaker: ts is second-precision
            ->forPage($page, $perPage)
            ->get();

        // Build a minimal set of user_id -> display label for actor/target
        // names. One query, no N+1 (LR-104).
        $userIds = $rows->pluck('user_id')
            ->merge($rows->pluck('target_user_id'))
            ->filter()
            ->unique()
            ->values();

        $userMap = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $data = $rows->map(function (ActivityLog $log) use ($userMap) {
            $actor = $log->user_id ? $userMap->get($log->user_id) : null;
            $target = $log->target_user_id ? $userMap->get($log->target_user_id) : null;

            return [
                'id' => (int) $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $actor ? [
                    'id' => (int) $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ] : null,
                'target_user' => $target ? [
                    'id' => (int) $target->id,
                    'name' => $target->name,
                ] : null,
                'scope_type' => $log->scope_type,
                'scope_id' => $log->scope_id,
                'role' => $log->role,
                'ip_address' => $log->ip_address,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'limit' => self::AUDIT_RECENT_HARD_LIMIT,
                'returned' => count($data),
            ],
        ]);
    }
}
