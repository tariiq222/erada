<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\OtpService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public const NEUTRAL_FORGOT = 'إذا كان بريدك مسجلاً وحسابك مفعّل، فسنرسل لك رمز تحقق.';

    public function __construct(private readonly OtpService $otp) {}

    public function forgot(Request $request): JsonResponse
    {
        $email = strtolower((string) $request->validate(['email' => 'required|email'])['email']);

        $user = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('registration_status', 'active')
            ->where('is_active', true)
            ->first();

        if ($user) {
            $this->otp->issue($email, OtpPurpose::PasswordReset, $request->ip(), $request->userAgent());
        } else {
            // Equalize cost: no-match path performs a dummy bcrypt so the timing
            // does not leak whether the email exists or the account is active.
            Hash::check('000000', self::dummyHash());
        }

        return response()->json(['message' => self::NEUTRAL_FORGOT]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $email = strtolower($data['email']);

        if (! $this->otp->verify($email, OtpPurpose::PasswordReset, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['رمز التحقق غير صحيح أو منتهي.']]);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('registration_status', 'active')
            ->where('is_active', true)
            ->firstOrFail();

        $user->update(['password' => Hash::make($data['password'])]);
        // Revoke all Sanctum tokens to force re-login on other devices.
        $user->tokens()->delete();

        ActivityLog::logPasswordChange($user, $request->ip(), $request->userAgent());

        return response()->json(['message' => 'تم تحديث كلمة المرور. الرجاء تسجيل الدخول مجدداً.']);
    }

    private static ?string $dummyHash = null;

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('forgot-timing-equalization');
    }
}
