<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuthSecurityService;
use App\Modules\Core\Services\TwoFactorService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * متحكم المصادقة الثنائية (2FA)
 */
class TwoFactorController extends Controller
{
    /**
     * معالجة الأخطاء غير المتوقعة
     */
    private function handleException(\Throwable $e, string $context): JsonResponse
    {
        if ($e instanceof AuthorizationException
            || $e instanceof AuthenticationException
            || $e instanceof ValidationException
            || $e instanceof ModelNotFoundException
            || $e instanceof HttpException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException) {
            throw $e;
        }

        $errorId = uniqid('2fa_err_', true);
        Log::error("TwoFactorController error: {$context}", [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
            'error_id' => $errorId,
        ], 500);
    }

    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly AuthSecurityService $authSecurityService
    ) {}

    /**
     * الحصول على حالة 2FA للمستخدم الحالي
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'enabled' => $this->twoFactorService->isEnabled($user),
                'required' => $this->twoFactorService->isRequired($user),
                'confirmed' => ! empty($user->two_factor_confirmed_at),
                'recovery_codes_remaining' => $this->twoFactorService->isEnabled($user)
                    ? $this->twoFactorService->getRemainingRecoveryCodes($user)
                    : 0,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'status');
        }
    }

    /**
     * بدء تفعيل 2FA (الخطوة 1)
     */
    public function enable(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // التحقق من كلمة المرور الحالية
            $validated = $request->validate([
                'password' => 'required|string',
            ]);

            if (! Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['كلمة المرور غير صحيحة'],
                ]);
            }

            // التحقق من أن 2FA غير مفعل مسبقاً
            if ($this->twoFactorService->isEnabled($user)) {
                return response()->json([
                    'message' => 'المصادقة الثنائية مفعلة مسبقاً',
                ], 400);
            }

            // تفعيل 2FA
            $result = $this->twoFactorService->enable($user);

            Log::info('2FA setup initiated', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'تم إنشاء إعدادات المصادقة الثنائية',
                'secret' => $result['secret'],
                'qr_code_url' => $result['qr_code_url'],
                'recovery_codes' => $result['recovery_codes'],
                'instructions' => [
                    'ar' => 'امسح رمز QR باستخدام تطبيق المصادقة (مثل Google Authenticator)',
                    'en' => 'Scan the QR code using your authenticator app (e.g., Google Authenticator)',
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'enable');
        }
    }

    /**
     * تأكيد تفعيل 2FA (الخطوة 2)
     */
    public function confirm(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'code' => 'required|string|size:6',
            ]);

            // التحقق من أن المستخدم بدأ عملية التفعيل
            if (empty($user->two_factor_secret)) {
                return response()->json([
                    'message' => 'يجب بدء عملية تفعيل المصادقة الثنائية أولاً',
                ], 400);
            }

            // التحقق من أن 2FA لم يتم تأكيده مسبقاً
            if (! empty($user->two_factor_confirmed_at)) {
                return response()->json([
                    'message' => 'المصادقة الثنائية مؤكدة مسبقاً',
                ], 400);
            }

            // التحقق من الكود
            if (! $this->twoFactorService->confirm($user, $validated['code'])) {
                throw ValidationException::withMessages([
                    'code' => ['الكود غير صحيح'],
                ]);
            }

            Log::info('2FA enabled', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'two_factor_enabled',
                'organization_id' => $user->organization_id,
                'description' => 'تم تفعيل المصادقة الثنائية',
                'loggable_type' => get_class($user),
                'loggable_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'تم تفعيل المصادقة الثنائية بنجاح',
                'enabled' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'confirm');
        }
    }

    /**
     * إلغاء تفعيل 2FA
     */
    public function disable(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'password' => 'required|string',
                'code' => 'required|string',
            ]);

            // التحقق من كلمة المرور
            if (! Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['كلمة المرور غير صحيحة'],
                ]);
            }

            // التحقق من كود 2FA
            if (! $this->twoFactorService->verify($user, $validated['code'])) {
                throw ValidationException::withMessages([
                    'code' => ['الكود غير صحيح'],
                ]);
            }

            // التحقق من أن المستخدم ليس مطلوباً منه 2FA
            if ($this->twoFactorService->isRequired($user) && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'لا يمكن إلغاء المصادقة الثنائية لحسابك (مطلوبة للحسابات الإدارية)',
                ], 403);
            }

            // إلغاء التفعيل
            $this->twoFactorService->disable($user);

            Log::info('2FA disabled', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'two_factor_disabled',
                'organization_id' => $user->organization_id,
                'description' => 'تم إلغاء المصادقة الثنائية',
                'loggable_type' => get_class($user),
                'loggable_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'تم إلغاء تفعيل المصادقة الثنائية',
                'enabled' => false,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'disable');
        }
    }

    /**
     * إعادة توليد أكواد الاسترداد
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $attempts = (int) cache()->get('2fa_recovery_regen_'.$user->id, 0);
            if ($attempts >= 3) {
                return response()->json(['message' => 'تجاوزت الحد. حاول بعد ساعة.'], 429);
            }
            cache()->put('2fa_recovery_regen_'.$user->id, $attempts + 1, now()->addHour());

            $validated = $request->validate([
                'password' => 'required|string',
            ]);

            // التحقق من كلمة المرور
            if (! Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['كلمة المرور غير صحيحة'],
                ]);
            }

            // التحقق من أن 2FA مفعل
            if (! $this->twoFactorService->isEnabled($user)) {
                return response()->json([
                    'message' => 'المصادقة الثنائية غير مفعلة',
                ], 400);
            }

            $recoveryCodes = $this->twoFactorService->regenerateRecoveryCodes($user);

            Log::info('2FA recovery codes regenerated', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'two_factor_recovery_regenerated',
                'organization_id' => $user->organization_id,
                'description' => 'تم إعادة توليد أكواد الاسترداد',
                'loggable_type' => get_class($user),
                'loggable_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'تم إعادة توليد أكواد الاسترداد',
                'recovery_codes' => $recoveryCodes,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'regenerateRecoveryCodes');
        }
    }

    /**
     * التحقق من كود 2FA (يُستخدم في تسجيل الدخول)
     */
    public function verify(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'code' => 'required|string|min:6|max:10',
                'pending_token' => 'required|string',
            ]);

            // التحقق من صحة pending_token (يجب أن يكون تم إنشاؤه في login)
            $pendingData = cache()->get('2fa_pending_'.$validated['pending_token']);
            if (! $pendingData || $pendingData['user_id'] != $validated['user_id']) {
                return response()->json([
                    'message' => 'جلسة التحقق منتهية. يرجى تسجيل الدخول مرة أخرى',
                ], 401);
            }

            // ✅ التحقق من IP: الربط بين pending_token وعنوان IP الخاص بمُنشئ الطلب
            if (($pendingData['ip'] ?? null) !== $request->ip()) {
                Log::warning('2FA pending token IP mismatch', [
                    'user_id' => $validated['user_id'],
                    'token_ip' => $pendingData['ip'] ?? 'null',
                    'request_ip' => $request->ip(),
                ]);
                cache()->forget('2fa_pending_'.$validated['pending_token']);

                return response()->json([
                    'message' => 'جلسة التحقق غير صالحة - تم رصد محاولة اختراق',
                ], 401);
            }

            // ✅ Rate limit على محاولات verify (10 محاولات لكل pending_token)
            $tries = (int) cache()->get('2fa_tries_'.$validated['pending_token'], 0);
            if ($tries >= 10) {
                cache()->forget('2fa_pending_'.$validated['pending_token']);
                cache()->forget('2fa_tries_'.$validated['pending_token']);

                return response()->json([
                    'message' => 'تجاوزت الحد الأقصى للمحاولات. يرجى تسجيل الدخول مرة أخرى',
                ], 429);
            }

            $user = User::find($validated['user_id']);
            if (! $user) {
                return response()->json(['message' => 'المستخدم غير موجود'], 404);
            }

            $eligibility = $this->authSecurityService->canAttemptLogin($user->email, $request->ip());
            if (! $user->is_active || ! $eligibility['allowed']) {
                return response()->json([
                    'message' => 'تعذر إكمال تسجيل الدخول',
                ], 403);
            }

            // التحقق من الكود
            if (! $this->twoFactorService->verify($user, $validated['code'])) {
                cache()->put('2fa_tries_'.$validated['pending_token'], $tries + 1, now()->addMinutes(5));
                throw ValidationException::withMessages([
                    'code' => ['الكود غير صحيح'],
                ]);
            }

            $this->authSecurityService->recordSuccessfulLogin(
                $user,
                $request->ip(),
                $request->userAgent()
            );

            try {
                ActivityLog::logLogin($user, $request->ip(), $request->userAgent());
            } catch (\Exception $e) {
                Log::warning('Failed to log 2FA login: '.$e->getMessage());
            }

            // حذف pending token وعداد المحاولات
            cache()->forget('2fa_pending_'.$validated['pending_token']);
            cache()->forget('2fa_tries_'.$validated['pending_token']);

            // إنشاء token الجلسة الفعلي
            $token = $user->createToken('auth-token')->plainTextToken;

            Log::info('2FA verification successful', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            $response = response()->json([
                'message' => 'تم التحقق بنجاح',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

            // إضافة Token في HttpOnly Cookie
            $cookieMinutes = config('sanctum.expiration', 60 * 24 * 7);
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
        } catch (\Throwable $e) {
            return $this->handleException($e, 'verify');
        }
    }
}
