<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Models\EmailOtp;
use App\Modules\Core\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OtpService
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    private static ?string $dummyHash = null;

    public function issue(string $email, OtpPurpose $purpose, ?string $ip = null, ?string $userAgent = null): string
    {
        $email = strtolower(trim($email));

        EmailOtp::where('email', $email)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailOtp::create([
            'email' => $email,
            'purpose' => $purpose->value,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
            'ip' => $ip,
            'user_agent' => Str::limit((string) $userAgent, 255, ''),
        ]);

        Notification::route('mail', $email)
            ->notify(new EmailOtpNotification($code, $purpose));

        return $code;
    }

    /**
     * Verify a one-time code for the given email and purpose.
     *
     * Defense-in-depth: the IP/email rate limiter (a separate concern) is the
     * primary throttle on guessing. This method adds a cross-window failed-attempt
     * budget so that re-issuing a code cannot reset an attacker's brute-force
     * budget, an atomic single-use claim to prevent double-spend under concurrency,
     * and timing equalization so every failure path costs roughly the same.
     */
    public function verify(string $email, OtpPurpose $purpose, string $code): bool
    {
        $email = strtolower(trim($email));

        // Cross-window brute-force budget so re-issuing cannot reset the attacker's
        // budget. Defense-in-depth: the IP/email rate limiter (separate concern) is
        // the primary throttle; this caps total failed guesses within the TTL window.
        $recentFailures = (int) EmailOtp::where('email', $email)
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', now()->subMinutes(self::TTL_MINUTES))
            ->sum('attempts');

        $otp = EmailOtp::where('email', $email)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        // Timing equalization: every failure path runs one Hash::check so "no pending
        // code" is indistinguishable by timing from "wrong code".
        if (! $otp
            || $otp->expires_at->isPast()
            || $otp->attempts >= self::MAX_ATTEMPTS
            || $recentFailures >= self::MAX_ATTEMPTS) {
            Hash::check($code, self::dummyHash());

            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }

        // Atomic single-use claim: only one concurrent caller can flip consumed_at.
        $claimed = EmailOtp::where('id', $otp->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $claimed === 1;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('otp-timing-equalization-placeholder');
    }
}
