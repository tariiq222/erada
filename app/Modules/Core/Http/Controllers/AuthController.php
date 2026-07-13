<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Http\Requests\ChangePasswordRequest;
use App\Modules\Core\Http\Requests\LoginRequest;
use App\Modules\Core\Http\Requests\UpdateProfileRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuthSecurityService;
use App\Modules\Core\Services\TwoFactorService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * رسالة خطأ موحّدة لتسجيل الدخول.
     *
     * تُستخدم لـ:
     * - مستخدم غير موجود
     * - كلمة مرور خاطئة
     * - حساب مقفل
     *
     * الهدف: منع تعداد المستخدمين (User Enumeration) عبر اختلاف رسائل الخطأ.
     * التفاصيل الحساسة (عدد المحاولات المتبقية، وقت القفل، سبب الفشل)
     * تُسجَّل في الـ audit log فقط، ولا تتسرّب إلى الاستجابة العامة.
     */
    private const UNIFIED_LOGIN_ERROR_MESSAGE = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';

    /**
     * Hash مسبق الحساب (bcrypt) لمعادلة توقيت الاستجابة عند عدم وجود المستخدم.
     *
     * يُستهلك نفس الوقت تقريباً الذي يستهلكه `Hash::check` على مستخدم حقيقي،
     * مما يُسطّح الفرق الزمني بين "المستخدم غير موجود" و"المستخدم موجود وكلمة
     * المرور خاطئة"، ويمنع مهاجمًا من قياس الزمن لاستنتاج وجود حساب معيّن.
     */
    private static ?string $timingEqualizationHash = null;

    private static function timingEqualizationHash(): string
    {
        if (self::$timingEqualizationHash === null) {
            self::$timingEqualizationHash = Hash::make('__iradah_pmo_timing_equalization__');
        }

        return self::$timingEqualizationHash;
    }

    /**
     * TTL for an issued 2FA pending_token (seconds). Single-use, scoped to
     * the (user_id, ip) tuple, deleted on consume or on first successful verify.
     */
    private const PENDING_2FA_TTL_SECONDS = 300;

    /**
     * Mint a single-use pending_2fa challenge keyed to (user_id, ip).
     *
     * The cache entry stores both the user_id AND the requesting IP so the
     * /api/2fa/verify route (TwoFactorController::verify) can compare
     * against the IP of the verifying request. The token is consumed
     * (forgotten from cache) on the first successful verify or on TTL
     * expiry — whichever happens first.
     */
    private function issuePendingTwoFactorChallenge(User $user, ?string $ip): string
    {
        $token = Str::random(48);

        cache()->put(
            '2fa_pending_'.$token,
            [
                'user_id' => (int) $user->id,
                'ip' => $ip,
                'issued_at' => now()->toIso8601String(),
                'expires_at' => now()->addSeconds(self::PENDING_2FA_TTL_SECONDS)->toIso8601String(),
            ],
            self::PENDING_2FA_TTL_SECONDS,
        );

        return $token;
    }

    public function __construct(
        private readonly AuthSecurityService $securityService,
        private readonly TwoFactorService $twoFactorService
    ) {}

    /**
     * تسجيل الدخول
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            // 1. التحقق من إمكانية محاولة الدخول (Account Lockout + IP blocking)
            $canAttempt = $this->securityService->canAttemptLogin($validated['email'], $ip);
            if (! $canAttempt['allowed']) {
                // حظر IP (دفاع DoS على مستوى الشبكة) — رسالة مختلفة عن باقي الأخطاء
                // لأنها تشير إلى الـ IP لا إلى المستخدم. التفاصيل (retry_after) هنا
                // مقبولة لأنها لا تكشف عن وجود/عدم وجود حساب.
                if (($canAttempt['reason_type'] ?? null) === 'ip_blocked') {
                    Log::warning('Login attempt blocked by IP', [
                        'email' => $validated['email'],
                        'ip' => $ip,
                        'retry_after' => $canAttempt['retry_after'] ?? null,
                    ]);

                    return response()->json([
                        'message' => $canAttempt['reason'],
                        'retry_after' => $canAttempt['retry_after'] ?? null,
                    ], 429);
                }

                // قفل الحساب (Account Locked): نُسقط في نفس مسار "كلمة مرور خاطئة"
                // ونُرجع رسالة موحّدة. لا نكشف retry_after / locked_until / reason
                // في الاستجابة العامة — هذه التفاصيل تذهب إلى الـ audit log فقط.
                Log::warning('Login attempt on locked account (public path)', [
                    'email' => $validated['email'],
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);

                // نقوم بـ Hash::check وهمي لمعادلة التوقيت (المستخدم موجود، لكن
                // لا نريد أن نكشف أنه مقفل عبر اختلاف زمني).
                $user = User::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->first();
                if ($user) {
                    Hash::check($validated['password'], $user->password);
                } else {
                    Hash::check($validated['password'], self::timingEqualizationHash());
                }

                try {
                    ActivityLog::logFailedLogin($validated['email'], $ip, $userAgent);
                } catch (\Exception $e) {
                    Log::warning('Failed to log failed login: '.$e->getMessage());
                }

                throw ValidationException::withMessages([
                    'email' => [self::UNIFIED_LOGIN_ERROR_MESSAGE],
                ]);
            }

            // 2. البحث عن المستخدم
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->first();

            // 3. التحقق من كلمة المرور
            if (! $user || ! Hash::check($validated['password'], $user->password)) {
                // معادلة التوقيت: عند عدم وجود المستخدم، نقوم بـ Hash::check وهمي
                // لتسطيح الفرق الزمني بين "المستخدم غير موجود" و"المستخدم موجود
                // وكلمة المرور خاطئة".
                if (! $user) {
                    Hash::check($validated['password'], self::timingEqualizationHash());
                }

                // تسجيل المحاولة الفاشلة (يزيد العداد، قد يقفل الحساب)
                $attemptResult = $this->securityService->recordFailedAttempt(
                    $validated['email'],
                    $ip,
                    $userAgent
                );

                // كل التفاصيل الحساسة (سبب الفشل، عدد المحاولات المتبقية، القفل)
                // تذهب إلى الـ audit log فقط — لا إلى الاستجابة العامة.
                Log::warning('Failed login attempt', [
                    'email' => $validated['email'],
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'reason' => $attemptResult['reason'] ?? 'unknown',
                    'user_exists' => $user !== null,
                    'locked' => $attemptResult['locked'] ?? false,
                    'locked_until' => $attemptResult['locked_until'] ?? null,
                    'remaining_attempts' => $attemptResult['remaining_attempts'] ?? null,
                ]);

                try {
                    ActivityLog::logFailedLogin($validated['email'], $ip, $userAgent);
                } catch (\Exception $e) {
                    Log::warning('Failed to log failed login: '.$e->getMessage());
                }

                // رسالة موحّدة — لا تكشف سبب الفشل، لا تكشف عدد المحاولات،
                // لا تكشف مدة القفل. كل ذلك في الـ audit log.
                throw ValidationException::withMessages([
                    'email' => [self::UNIFIED_LOGIN_ERROR_MESSAGE],
                ]);
            }

            // 4. التحقق من أن الحساب نشط
            if (! $user->is_active) {
                Log::warning('Inactive user login attempt', [
                    'user_id' => $user->id,
                    'ip' => $ip,
                ]);

                throw ValidationException::withMessages([
                    'email' => ['هذا الحساب غير مفعل.'],
                ]);
            }

            // 5. تسجيل الدخول الناجح
            $this->securityService->recordSuccessfulLogin($user, $ip, $userAgent);

            // 6. CORE-004 fix — enforced 2FA challenge flow.
            //
            // A confirmed-2FA user MUST NOT receive a Sanctum token or
            // auth_token cookie after a successful password check alone.
            // We mint a single-use, short-lived pending_token (scoped to
            // the (user_id, ip) tuple) and require the client to complete
            // the 2FA challenge via /api/2fa/verify before any session is
            // established. The /api/2fa/verify route is the only path that
            // mints the Sanctum token and sets the auth_token cookie.
            if ($this->twoFactorService->isEnabled($user)) {
                $pendingToken = $this->issuePendingTwoFactorChallenge($user, $ip);

                Log::info('2FA challenge issued after password verification', [
                    'user_id' => $user->id,
                    'ip' => $ip,
                ]);

                return response()->json([
                    'two_factor_required' => true,
                    'user_id' => (int) $user->id,
                    'pending_token' => $pendingToken,
                    'message' => 'كلمة المرور صحيحة. يلزم التحقق من رمز المصادقة الثنائية.',
                ], 200);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            Log::info('User logged in', [
                'user_id' => $user->id,
                'ip' => $ip,
            ]);

            try {
                ActivityLog::logLogin($user, $ip, $userAgent);
            } catch (\Exception $e) {
                Log::warning('Failed to log login: '.$e->getMessage());
            }

            // إنشاء Response مع HttpOnly Cookie
            $response = response()->json([
                'user' => $this->formatUser($user),
            ]);

            // Cookie lifetime must match the token expiration; null means
            // session-cookie (cleared on browser close). Default to 24h so the
            // cookie never outlives the token when the env is unset.
            $cookieMinutes = config('sanctum.expiration') ?? 60 * 24;
            // M-12: derive Secure from transport/session config, not the env string.
            $secure = config('session.secure') ?? $request->isSecure();

            return $response->cookie(
                'auth_token',
                $token,
                $cookieMinutes,
                '/',
                null,
                $secure,
                true,
                false,
                'Lax'
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $errorId = uniqid('login_err_');
            Log::error('Login error', [
                'error_id' => $errorId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تسجيل الدخول',
                'error_id' => $errorId,
            ], 500);
        }
    }

    /**
     * تسجيل الخروج
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();

        Log::info('User logged out', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        ActivityLog::logLogout($user, $request->ip(), $request->userAgent());

        $response = response()->json([
            'message' => 'تم تسجيل الخروج بنجاح',
        ]);

        return $response->cookie(
            'auth_token',
            '',
            -1,
            '/',
            null,
            config('session.secure') ?? $request->isSecure(),
            true,
            false,
            'Lax'
        );
    }

    /**
     * بيانات المستخدم الحالي
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    /**
     * تحديث تفضيل اللغة
     */
    public function updateLocale(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => 'required|string|in:ar,en',
        ]);

        $request->user()->update(['preferred_locale' => $validated['locale']]);
        session(['locale' => $validated['locale']]);
        app()->setLocale($validated['locale']);

        return response()->json([
            'locale' => $validated['locale'],
        ]);
    }

    /**
     * تحديث الملف الشخصي
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->update($validated);

        Log::info('User updated profile', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * تغيير كلمة المرور
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // حذف جميع الـ tokens الأخرى للأمان
        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        }

        Log::info('User changed password', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        ActivityLog::logPasswordChange($user, $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح',
        ]);
    }

    /** @return array<string, mixed> */
    private function formatUser(User $user): array
    {
        // Authorization assignments can change independently of users.updated_at.
        // Build this projection from current canonical rows so an explicit refresh
        // after a grant, revocation, or organization switch never returns stale access.
        return $this->buildFormatUserPayload($user);
    }

    /**
     * Build the /me payload for a user from active canonical assignments.
     *
     * @return array<string, mixed>
     */
    private function buildFormatUserPayload(User $user): array
    {
        try {
            $user->load(['department']);

            try {
                // Navigation/module availability is projected from every active
                // canonical assignment, including department/project scopes.
                // Record mutations still use target-bound AccessDecision checks
                // and per-record abilities; this list never widens backend access.
                $capabilities = $user->canonicalCapabilityNames();
            } catch (\Exception $e) {
                Log::warning('Failed to load canonical capabilities for user '.$user->id.': '.$e->getMessage());
                $capabilities = [];
            }

            $access = array_fill_keys($capabilities, true);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'extension' => $user->extension,
                'job_title' => $user->job_title,
                'is_active' => $user->is_active,
                'department' => $user->department ? [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                ] : null,
                'preferred_locale' => $user->preferred_locale,
                'capabilities' => $capabilities,
                'access' => $access,
                'role_assignments' => $this->canonicalRoleAssignments($user),
                'organization_id' => $user->organization_id,
                // super_admin may switch across all orgs; everyone else is locked to their own.
                'organizations' => ($user->isSuperAdmin()
                    ? Organization::query()->where('is_active', true)
                    : Organization::query()->whereKey($user->organization_id)
                )->orderBy('name')->get(['id', 'name', 'code', 'is_active']),
            ];
        } catch (\Exception $e) {
            Log::error('Error formatting user: '.$e->getMessage());

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'capabilities' => [],
                'access' => [],
                'role_assignments' => [],
            ];
        }
    }

    /**
     * @return list<array{id: int, role_id: int, role: string, label: string, scope_type: string, scope_id: int|null, organization_id: int|null, inherit_to_children: bool, expires_at: string|null, source: string}>
     */
    private function canonicalRoleAssignments(User $user): array
    {
        try {
            return AuthorizationRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->with('role:id,name,label')
                ->get()
                ->map(fn (AuthorizationRoleAssignment $assignment) => [
                    'id' => (int) $assignment->id,
                    'role_id' => (int) $assignment->authorization_role_id,
                    'role' => $assignment->role?->name ?? '',
                    'label' => $assignment->role?->label ?? '',
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                    'organization_id' => $assignment->organization_id === null ? null : (int) $assignment->organization_id,
                    'inherit_to_children' => (bool) $assignment->inherit_to_children,
                    'expires_at' => $assignment->expires_at?->toISOString(),
                    'source' => $assignment->source,
                ])
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::warning('Failed to load canonical role assignments for user '.$user->id.': '.$e->getMessage());

            return [];
        }
    }

    /**
     * التحقق من أن كلمة المرور ضعيفة أو تتبع أنماط شائعة
     */
    private function isSimilarToDefaultPassword(string $password): bool
    {
        $commonPasswords = [
            'password', 'password1', 'password123', 'password!',
            '12345678', '123456789', '1234567890', '12345678!',
            'qwerty123', 'admin123', 'welcome1', 'welcome123',
            'letmein', 'monkey123', 'dragon123', 'master123',
            'abc12345', 'iloveyou', 'sunshine', 'princess',
            'trustno1', 'shadow123', 'superman', 'batman123',
            'changeme', 'changeme1', 'changeme!',
            'P@ssw0rd', 'P@ssword', 'Admin@123', 'Admin1234',
        ];

        $lowerPassword = strtolower($password);

        foreach ($commonPasswords as $common) {
            if ($lowerPassword === strtolower($common)) {
                return true;
            }
        }

        if (preg_match('/^[a-z]{2,10}[@#$!%*?&]\d{4}$/i', $password)) {
            return true;
        }

        if (preg_match('/^[a-z]{2,6}.{0,2}\d{4}$/i', $password)) {
            return true;
        }

        // أنماط لوحة المفاتيح
        $keyboardSequences = ['qwerty', 'asdfgh', 'zxcvbn', 'qazwsx', '123456', 'abcdef'];
        foreach ($keyboardSequences as $seq) {
            if (stripos($lowerPassword, $seq) !== false) {
                return true;
            }
        }

        // أنماط متكررة (مثل aaaa1111, aaaaabbb)
        if (preg_match('/(.)\1{4,}/', $password)) {
            return true;
        }

        return false;
    }

    /**
     * الحصول على حالة أمان الحساب (للمديرين)
     */
    public function getSecurityStatus(Request $request, User $user): JsonResponse
    {
        // التحقق من الصلاحية
        if (! $request->user()->can('view', $user)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        return response()->json([
            'security' => $this->securityService->getAccountSecurityStatus($user),
        ]);
    }

    /**
     * إلغاء قفل حساب (للمديرين)
     */
    public function unlockAccount(Request $request, User $user): JsonResponse
    {
        // التحقق من الصلاحية عبر UserPolicy (يفرض عزل المؤسسة + حماية super_admin)
        if (! $request->user()->can('update', $user)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $this->securityService->unlockAccount($user);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'unlock_account',
            'description' => "إلغاء قفل حساب المستخدم: {$user->name}",
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم إلغاء قفل الحساب بنجاح',
        ]);
    }
}
