<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * خدمة المصادقة الثنائية (2FA) باستخدام TOTP
 *
 * تستخدم خوارزمية TOTP (Time-based One-Time Password) المتوافقة مع:
 * - Google Authenticator
 * - Microsoft Authenticator
 * - Authy
 * - وغيرها من تطبيقات المصادقة
 */
class TwoFactorService
{
    /**
     * طول الكود السري
     */
    private const SECRET_LENGTH = 32;

    /**
     * عدد أكواد الاسترداد
     */
    private const RECOVERY_CODES_COUNT = 8;

    /**
     * طول كود الاسترداد
     */
    private const RECOVERY_CODE_LENGTH = 10;

    /**
     * فترة صلاحية الكود (بالثواني)
     */
    private const TIME_STEP = 30;

    /**
     * طول كود TOTP
     */
    private const CODE_LENGTH = 6;

    /**
     * نافذة التحقق (عدد الفترات المسموح بها للتأخير)
     */
    private const TIME_WINDOW = 1;

    /**
     * تفعيل 2FA للمستخدم
     *
     * @return array{secret: string, qr_code_url: string, recovery_codes: array}
     */
    public function enable(User $user): array
    {
        $secret = $this->generateSecret();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_code_hashes' => $this->hashRecoveryCodes($recoveryCodes),
            'two_factor_confirmed_at' => null, // لم يتم التأكيد بعد
        ]);

        return [
            'secret' => $secret,
            'qr_code_url' => $this->getQrCodeUrl($user, $secret),
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * تأكيد تفعيل 2FA (بعد التحقق من الكود الأول)
     */
    public function confirm(User $user, string $code): bool
    {
        if (! $this->verify($user, $code)) {
            return false;
        }

        $user->update([
            'two_factor_confirmed_at' => now(),
        ]);

        return true;
    }

    /**
     * إلغاء تفعيل 2FA
     */
    public function disable(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_code_hashes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * التحقق من كود TOTP أو كود الاسترداد
     */
    public function verify(User $user, string $code): bool
    {
        // تنظيف الكود
        $code = preg_replace('/\s+/', '', $code);

        // محاولة التحقق من كود TOTP
        if (strlen($code) === self::CODE_LENGTH && $this->verifyTotp($user, $code)) {
            return true;
        }

        // محاولة التحقق من كود الاسترداد
        if ($this->verifyRecoveryCode($user, $code)) {
            return true;
        }

        return false;
    }

    /**
     * التحقق من كود TOTP
     */
    public function verifyTotp(User $user, string $code): bool
    {
        $secret = $this->getSecret($user);
        if (! $secret) {
            return false;
        }

        $currentTime = time();
        $timeStep = self::TIME_STEP;

        // التحقق من الكود في نافذة زمنية (للتعامل مع التأخير)
        for ($i = -self::TIME_WINDOW; $i <= self::TIME_WINDOW; $i++) {
            $expectedCode = $this->generateTotp($secret, floor(($currentTime + ($i * $timeStep)) / $timeStep));
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من كود الاسترداد
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $hashedCodes = $this->getRecoveryCodeHashes($user);

        if (empty($hashedCodes)) {
            return false;
        }

        $code = strtoupper($code);

        foreach ($hashedCodes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                // حذف كود الاسترداد المستخدم (إبطال بعد الاستخدام)
                unset($hashedCodes[$index]);
                $user->update([
                    'two_factor_recovery_code_hashes' => array_values($hashedCodes),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * إعادة توليد أكواد الاسترداد
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_recovery_code_hashes' => $this->hashRecoveryCodes($recoveryCodes),
        ]);

        return $recoveryCodes;
    }

    /**
     * هل المستخدم مفعل لديه 2FA؟
     */
    public function isEnabled(User $user): bool
    {
        return ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at);
    }

    /**
     * هل يجب على المستخدم تفعيل 2FA؟ (للحسابات الإدارية)
     */
    public function isRequired(User $user): bool
    {
        // مطلوب للـ Super Admin و Admin
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        // أو إذا تم تحديده يدوياً
        return $user->two_factor_required;
    }

    /**
     * الحصول على عدد أكواد الاسترداد المتبقية
     */
    public function getRemainingRecoveryCodes(User $user): int
    {
        return count($this->getRecoveryCodeHashes($user));
    }

    // ========== Private Methods ==========

    /**
     * توليد كود سري جديد
     */
    private function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * توليد أكواد استرداد
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(self::RECOVERY_CODE_LENGTH));
        }

        return $codes;
    }

    /**
     * الحصول على الكود السري المُخزن
     */
    private function getSecret(User $user): ?string
    {
        if (empty($user->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * الحصول على أكواد الاسترداد
     */
    private function getRecoveryCodeHashes(User $user): array
    {
        return $user->two_factor_recovery_code_hashes ?? [];
    }

    /**
     * تجزئة أكواد الاسترداد (Hash::make) قبل التخزين
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $codes);
    }

    /**
     * توليد كود TOTP
     */
    private function generateTotp(string $secret, int $counter): string
    {
        $secretKey = $this->base32Decode($secret);

        // Counter to binary (64-bit big-endian)
        $binaryCounter = pack('N*', 0, $counter);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $binaryCounter, $secretKey, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, self::CODE_LENGTH);

        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * فك ترميز Base32
     */
    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper($data);
        $data = str_replace('=', '', $data);

        $buffer = 0;
        $bufferSize = 0;
        $result = '';

        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $value = strpos($alphabet, $char);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bufferSize += 5;

            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $result .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }

        return $result;
    }

    /**
     * الحصول على رابط QR Code للإعداد
     */
    private function getQrCodeUrl(User $user, string $secret): string
    {
        $issuer = config('app.name', 'Erada System');
        $accountName = $user->email;

        $otpauthUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer),
            self::CODE_LENGTH,
            self::TIME_STEP
        );

        // رابط Google Charts API لتوليد QR Code
        return sprintf(
            'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=%s',
            urlencode($otpauthUrl)
        );
    }

    /**
     * الحصول على كود TOTP الحالي (للاختبارات فقط)
     */
    public function getCurrentOtp(User $user): ?string
    {
        $secret = $this->getSecret($user);
        if (! $secret) {
            return null;
        }

        return $this->generateTotp($secret, (int) floor(time() / self::TIME_STEP));
    }

    /**
     * الحصول على رابط otpauth للتطبيقات
     */
    public function getOtpauthUrl(User $user): ?string
    {
        $secret = $this->getSecret($user);
        if (! $secret) {
            return null;
        }

        $issuer = config('app.name', 'Erada System');
        $accountName = $user->email;

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer),
            self::CODE_LENGTH,
            self::TIME_STEP
        );
    }
}
