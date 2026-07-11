<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\WelcomeNotification;
use App\Modules\Core\Rules\PhoneFormat;
use App\Modules\Core\Services\OrganizationRegistrationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegistrationController extends Controller
{
    /**
     * Register a new user directly (no OTP verification required).
     */
    public function register(Request $request, OrganizationRegistrationInvitationService $invitations): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'invite_token' => ['required', 'string', 'size:64'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', new PhoneFormat],
        ]);

        $user = DB::transaction(function () use ($validated, $invitations): User {
            $invitation = $invitations->consume($validated['invite_token'], $validated['email']);

            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'department_id' => $invitation->department_id,
                'job_title' => $validated['job_title'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'organization_id' => $invitation->organization_id,
                'is_active' => true,
                'registration_status' => 'approved',
            ]);
        });

        // email_verified_at is guarded (not mass-assignable); mark verified directly.
        $user->forceFill(['email_verified_at' => now()])->save();

        // Drop a welcome notification in the in-app inbox so the user has
        // visible confirmation that their account exists after the SPA
        // navigates them to /dashboard. Database channel only — no email.
        $user->notify(new WelcomeNotification($user));

        // Issue Sanctum token + set HttpOnly cookie (same as login flow).
        $cookieMinutes = config('sanctum.expiration') ?? 60 * 24;
        $secure = config('session.secure') ?? $request->isSecure();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $user->only([
                'id', 'name', 'email', 'department_id',
                'job_title', 'phone', 'organization_id',
            ]),
        ], 201)->cookie(
            'auth_token',
            $token,
            $cookieMinutes,
            '/',
            null,
            $secure,
            true,    // HttpOnly
            false,   // raw
            'Lax'
        );
    }
}
