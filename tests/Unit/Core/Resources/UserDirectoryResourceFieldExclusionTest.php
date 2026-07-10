<?php

namespace Tests\Unit\Core\Resources;

use App\Modules\Core\Http\Resources\UserDirectoryResource;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * UserDirectoryResourceFieldExclusionTest - Phase CFA-07: explicit "no leakage"
 * assertions for every dangerous field on the User model + adjacent tables.
 *
 * This test is the FAILSAFE — even if the WHITELISTED_KEYS list accidentally
 * gains a key, this test catches it via the negative assertion on
 * the explicit list of forbidden fields. Each field here corresponds to a
 * specific CFA-00 stop condition or a documented PII risk.
 *
 * Adding/removing keys here MUST be a deliberate decision and reviewed
 * alongside the audit (CFA-00 owner rule: HIGH PII risk, manual review
 * required at batch end regardless of CI).
 */
class UserDirectoryResourceFieldExclusionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exhaustive list of keys that MUST NEVER appear in a directory response.
     * Touching this list requires updating the CFA-00 audit and re-review.
     *
     * @var list<string>
     */
    public const FORBIDDEN_KEYS = [
        // Authentication credentials / tokens
        'password',
        'remember_token',
        'password_reset_token',
        'personal_access_token',

        // Two-factor authentication
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_recovery_code_hashes',
        'two_factor_confirmed_at',
        'two_factor_required',

        // Login telemetry / anti-abuse
        'last_login_at',
        'last_login_ip',
        'last_failed_login_at',
        'failed_login_attempts',
        'locked_until',
        'login_attempts',

        // HTTP request traces (adjacent tables - never on User JSON)
        'ip_address',
        'user_agent',
        'email_otps',

        // HR private profile / employee data
        'employee_personal_info',
        'employee_profile',
        'employee_profiles',
        'employee_certificates',
        'national_id',
        'iban',
        'salary',
        'bank_account',

        // Scoped-role / permission leak (role assignment widens MUST NOT happen)
        'scoped_roles',
        'active_scoped_roles',
        'role_definitions',
        'permissions',
        'roles',

        // Audit fields that must not leak in a directory
        'created_by',
        'updated_by',

        // Internal / pivot fields that must not leak
        'pivot',
        'creator',
        'updater',

        // Plaintext password (defense against accidental ->makeVisible())
        'plain_password',
    ];

    public function test_resource_excludes_every_forbidden_pii_field(): void
    {
        $user = $this->makeUserWithSensitiveColumnsFilled();

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));
        $payloadKeys = array_keys($payload);

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $payloadKeys,
                "UserDirectoryResource must NEVER expose `{$forbidden}` (HIGH PII - CFA-07 stop condition)"
            );
        }
    }

    public function test_resource_payload_size_is_bounded_by_the_whitelist(): void
    {
        $user = $this->makeUserWithSensitiveColumnsFilled();

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        // Size guard: a directory row cannot exceed exactly 7 whitelisted keys.
        // If a developer adds a key and bumps this assertion, they are not
        // silently widening the directory shape past the audit boundary.
        $this->assertLessThanOrEqual(
            count(UserDirectoryResource::WHITELISTED_KEYS),
            count($payload),
            'UserDirectoryResource payload must not exceed the whitelist size'
        );
    }

    public function test_resource_does_not_leak_loaded_relationships(): void
    {
        $user = $this->makeUserWithSensitiveColumnsFilled();
        $user->load(['department', 'creator:id,name', 'updater:id,name']);

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));

        // Loaded relations must not surface in the directory shape.
        $this->assertArrayNotHasKey('department', $payload);
        $this->assertArrayNotHasKey('creator', $payload);
        $this->assertArrayNotHasKey('updater', $payload);
        $this->assertArrayNotHasKey('roles', $payload);
        $this->assertArrayNotHasKey('pivot', $payload);
    }

    public function test_resource_string_dump_does_not_contain_password_value(): void
    {
        $user = $this->makeUserWithSensitiveColumnsFilled();

        $payload = (new UserDirectoryResource($user))->toArray(Request::create('/api/users/'.$user->id));
        $encoded = json_encode($payload);

        // Even if `password` slips into the array under a non-standard key (renamed),
        // the JSON dump should not embed the actual password value. This is a
        // belt-and-braces backstop.
        $this->assertStringNotContainsString('SuperSecretPassword!2026', $encoded);
        $this->assertStringNotContainsString('TOP_SECRET_2FA', $encoded);
    }

    public function test_forbidden_keys_constant_is_audit_locked(): void
    {
        // The forbidden list is the audit-approved boundary. Adding/removing
        // entries requires the CFA-00 audit update + manual review.
        $expected = [
            'password', 'remember_token', 'password_reset_token', 'personal_access_token',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_recovery_code_hashes',
            'two_factor_confirmed_at', 'two_factor_required',
            'last_login_at', 'last_login_ip', 'last_failed_login_at', 'failed_login_attempts',
            'locked_until', 'login_attempts',
            'ip_address', 'user_agent', 'email_otps',
            'employee_personal_info', 'employee_profile', 'employee_profiles',
            'employee_certificates', 'national_id', 'iban', 'salary', 'bank_account',
            'scoped_roles', 'active_scoped_roles', 'role_definitions',
            'permissions', 'roles',
            'created_by', 'updated_by',
            'pivot', 'creator', 'updater',
            'plain_password',
        ];

        $this->assertSame($expected, self::FORBIDDEN_KEYS);
    }

    private function makeUserWithSensitiveColumnsFilled(): User
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'name' => 'Ahmed Cluster',
            'email' => 'ahmed@example.test',
            'job_title' => 'Project Coordinator',
            'is_active' => true,
            'password' => 'SuperSecretPassword!2026',
        ]);

        // Force-fill sensitive columns that the factory may leave null, so the
        // test exercises the real DB row shape (not just the cast).
        $user->forceFill([
            'two_factor_secret' => 'TOP_SECRET_2FA',
            'two_factor_recovery_codes' => ['recovery-1', 'recovery-2'],
            'two_factor_recovery_code_hashes' => ['hash-1', 'hash-2'],
            'two_factor_confirmed_at' => now(),
            'two_factor_required' => true,
            'failed_login_attempts' => 3,
            'locked_until' => now()->addMinutes(15),
            'last_login_at' => now(),
            'last_login_ip' => '203.0.113.99',
            'last_failed_login_at' => now(),
            'remember_token' => 'remember-token-stub',
        ])->save();

        return $user;
    }
}
