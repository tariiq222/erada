<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\WelcomeNotification;
use App\Modules\Core\Rules\DepartmentBelongsToOrganization;
use App\Modules\Core\Rules\PhoneFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegistrationController extends Controller
{
    /**
     * Register a new user directly (no OTP verification required).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id', new DepartmentBelongsToOrganization],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', new PhoneFormat],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'department_id' => $validated['department_id'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'organization_id' => $validated['organization_id'] ?? null,
            'is_active' => true,
            'registration_status' => 'approved',
        ]);

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
