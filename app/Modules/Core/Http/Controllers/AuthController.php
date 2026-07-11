<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Contracts\CapabilityProvider;
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
use Illuminate\Support\Facades\Cache;
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

            if ($this->twoFactorService->isEnabled($user)) {
                $pendingToken = Str::random(64);

                Cache::put('2fa_pending_'.$pendingToken, [
                    'user_id' => $user->id,
                    'ip' => $ip,
                ], now()->addMinutes(5));

                return response()->json([
                    'requires_2fa' => true,
                    'pending_token' => $pendingToken,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                ]);
            }

            // 5. تسجيل الدخول الناجح
            $this->securityService->recordSuccessfulLogin($user, $ip, $userAgent);

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

    /**
     * Two-tier ability contract.
     *
     * This method emits the GENERIC capability list consumed by menus and
     * buttons (auth/me.permissions). For per-record authorization ("can I edit
     * *this* project"), element endpoints carry their own `abilities` payload
     * computed by App\Modules\Shared\Support\ElementAbilities via
     * AccessDecision::can($user, $capability, $record). The frontend MUST read
     * record.abilities.* for any "can I act on this record" decision; never
     * infer it from auth/me.permissions, which is org/role-scope only.
     *
     * @return array<string, mixed>
     */
    private function formatUser(User $user): array
    {
        // /me is hit on every page load by the SPA; the heavy work below
        // (70+ Capability::can() decisions + multiple role/permission loads)
        // produces the same answer for any user whose role set hasn't changed.
        // Key the cache on the user's role timestamp (updated_at) so any role
        // assignment/role-definition mutation busts it within seconds; fall back
        // to a fixed 60s TTL on top of that for absolute upper bound.
        $roleVersion = $user->updated_at?->timestamp ?? 0;

        try {
            return Cache::remember(
                "auth:me:user:{$user->id}:rv:{$roleVersion}",
                now()->addSeconds(60),
                fn () => $this->buildFormatUserPayload($user)
            );
        } catch (\Exception $e) {
            // Cache backend failure shouldn't break login — fall through to direct build.
            Log::warning('AuthController formatUser cache failure', ['error' => $e->getMessage()]);

            return $this->buildFormatUserPayload($user);
        }
    }

    /**
     * Build the /me payload for a user. Heavy work (role/permission loads +
     * Capability::can() loop) — call from a Cache::remember wrapper, not directly.
     *
     * @return array<string, mixed>
     */
    private function buildFormatUserPayload(User $user): array
    {
        try {
            $user->load(['department']);

            $roles = [];
            // Canonical module.action capabilities the user effectively holds.
            // Surfaced as its own `capabilities` key (Phase 2 of
            // ADR-UNIFIED-ROLE-ACCESS) — this is the single maintained vocabulary.
            // The legacy `permissions[]` blob was removed in Phase 9.3 of the master
            // AuthZ unification plan; every frontend read goes through `user.access`
            // (canonical capabilities) or `user.capabilities` (canonical list). See
            // docs/authz/deprecation-policy.md for the migration context.
            $capabilities = [];

            try {
                $roles = $user->getRoleNames()->toArray();

                // قدرات المحرّك على مستوى المؤسسة (vocabulary موحّد module.action).
                // `capabilities` هو المصدر الوحيد لقرارات الـ SPA الآن.
                // ponytail: O(عدد القدرات) قرارات على /me (مسار غير حار)؛ لو صار حاراً
                // مرّر الأدوار النشطة مرة واحدة بدل استعلام لكل can().
                foreach (Capability::all() as $capability) {
                    if (AccessDecision::can($user, $capability)) {
                        $capabilities[] = $capability;
                    }
                }

                $capabilities = array_values(array_unique($capabilities));

                // Module-owned legacy flat capabilities (view_projects / create_projects /
                // view_risks / create_risks / ovr.create / ovr.view_own, ...) are surfaced
                // by CapabilityProvider implementations that each module tags into
                // `engined_capability_providers` from its service provider. Iterating the
                // tag here keeps AuthController decoupled from any specific module's
                // authorization service — adding a new flat capability becomes a
                // one-file, one-tag-line change in the owning module.
                $flags = [];
                foreach (app()->tagged('engined_capability_providers') as $provider) {
                    /** @var CapabilityProvider $provider */
                    $flags = array_merge($flags, $provider->userCapabilities($user));
                }
                foreach ($flags as $flag => $granted) {
                    if ($granted) {
                        $capabilities[] = $flag;
                    }
                }

                $capabilities = array_values(array_unique($capabilities));
            } catch (\Exception $e) {
                Log::warning('Failed to load roles/capabilities for user '.$user->id.': '.$e->getMessage());
            }

            // Phase 1 of the master AuthZ unification plan: an additive
            // `user.access` projection derived only from canonical
            // module.action capabilities (e.g. {projects: {view: true,
            // create: true}, tasks: {assign: true}}). Read-side only — does
            // not change any backend authorization decision and never grants
            // a capability that is not already in user.capabilities.
            $access = $this->projectAccessMap($capabilities);

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
                'roles' => $roles,
                // Canonical module.action capabilities (single vocabulary) and
                // the user's scoped role assignments — the canonical payload
                // after the Phase 9.3 cutover. The legacy `permissions[]` blob
                // is GONE; SPA gating reads `useCan('module.action')` (which
                // consults `user.access`) or `user.capabilities[]` for display.
                'capabilities' => $capabilities,
                // Structured access map for the SPA. Each value is `true`; the
                // frontend may read it as `user.access[module][action]`.
                'access' => $access,
                'scoped_roles' => $this->scopedRoleAssignments($user),
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
                'roles' => [],
                'capabilities' => [],
                'access' => [],
            ];
        }
    }

    /**
     * Project canonical dotted capabilities into the structured access map
     * consumed by the SPA (`user.access[module][action] === true`). Read-side
     * only — never grants a capability that isn't already in the engine
     * decision. Non-dotted or malformed values are silently skipped; the
     * upstream `$capabilities` array is already filtered by Capability::all()
     * but we re-check defensively.
     *
     * @param  list<string>  $capabilities
     * @return array<string, array<string, true>>
     */
    private function projectAccessMap(array $capabilities): array
    {
        $access = [];
        foreach ($capabilities as $capability) {
            if (! is_string($capability) || ! preg_match('/^[a-z_]+\.[a-z_]+$/', $capability)) {
                continue;
            }
            [$module, $action] = explode('.', $capability, 2);
            $access[$module][$action] = true;
        }

        return $access;
    }

    /**
     * The user's active scoped role assignments from model_has_scoped_roles.
     * Additive projection for /auth/me (Phase 2 of ADR-UNIFIED-ROLE-ACCESS);
     * previously absent from the payload. Read-only, no authorization decision.
     *
     * @return array<int, array{role: string, scope_type: string, scope_id: int, label: string}>
     */
    private function scopedRoleAssignments(User $user): array
    {
        try {
            return $user->activeScopedRoles()
                ->with('roleDefinition')
                ->get()
                ->map(fn ($assignment) => [
                    'role' => $assignment->role,
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => (int) $assignment->scope_id,
                    'label' => $assignment->display_name,
                ])
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::warning('Failed to load scoped roles for user '.$user->id.': '.$e->getMessage());

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
