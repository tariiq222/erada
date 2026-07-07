<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Http\Controllers\RegistrationApprovalController;
use App\Modules\Core\Models\EmployeeRosterEntry;
use App\Modules\Core\Notifications\RegistrationDecisionNotification;
use App\Modules\Core\Notifications\RegistrationPendingNotification;
use App\Modules\Core\Services\RegistrationAccessAssignmentService;
use App\Modules\Core\Services\RosterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Invariant tests for the simplified registration cutover
 * (docs/superpowers/plans/2026-07-06-simplified-registration.md).
 *
 * These tests assert what the codebase MUST NOT contain. If someone re-introduces
 * the old invite + admin-approval flow (or resurrects an old route, controller,
 * or enum case), these tests will fail loudly so the cutover stays clean.
 */
class RegistrationInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_old_register_routes_are_registered(): void
    {
        $router = app('router');
        $names = collect($router->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter()
            ->values();

        $this->assertNotContains('api/auth/register/start', $names, 'Legacy start endpoint must not exist.');
        $this->assertNotContains('api/auth/register/verify', $names, 'Legacy verify endpoint must not exist.');
        $this->assertNotContains('api/auth/register/complete', $names, 'Legacy complete endpoint must not exist.');

        $this->assertNotContains('api/registrations', $names, 'Admin approval queue must not exist.');
        $this->assertNotContains('api/registrations/{user}/approve', $names);
        $this->assertNotContains('api/registrations/{user}/reject', $names);
        $this->assertNotContains('api/registrations/bulk-approve', $names);

        $this->assertNotContains('api/roster/import', $names, 'Roster import endpoint must not exist.');
    }

    public function test_otp_registration_purpose_does_not_exist(): void
    {
        $cases = array_map(
            fn ($c) => $c->name,
            OtpPurpose::cases()
        );

        $this->assertNotContains('Registration', $cases, 'OtpPurpose::Registration must be removed.');
        $this->assertContains('PasswordReset', $cases, 'OtpPurpose::PasswordReset must remain.');
    }

    public function test_old_classes_are_not_loadable(): void
    {
        $this->assertFalse(
            class_exists(RegistrationApprovalController::class),
            'RegistrationApprovalController must be removed.'
        );
        $this->assertFalse(
            class_exists(RegistrationAccessAssignmentService::class),
            'RegistrationAccessAssignmentService must be removed.'
        );
        $this->assertFalse(
            class_exists(RosterImportService::class),
            'RosterImportService must be removed.'
        );
        $this->assertFalse(
            class_exists(RegistrationPendingNotification::class),
            'RegistrationPendingNotification must be removed.'
        );
        $this->assertFalse(
            class_exists(RegistrationDecisionNotification::class),
            'RegistrationDecisionNotification must be removed.'
        );
        $this->assertFalse(
            class_exists(EmployeeRosterEntry::class),
            'EmployeeRosterEntry model must be removed.'
        );
    }

    public function test_capability_and_permission_for_approving_registrations_are_gone(): void
    {
        $caps = (new \ReflectionClass(Capability::class))->getConstants();
        $this->assertArrayNotHasKey('USERS_APPROVE_REGISTRATIONS', $caps);

        $perms = array_map(fn ($c) => $c->value, Permission::cases());
        $this->assertNotContains('approve_registrations', $perms);
    }

    public function test_old_migration_files_are_gone(): void
    {
        $path = base_path('database/migrations/2026_07_06_000001_add_invite_token_to_employee_roster_entries.php');
        $this->assertFalse(
            File::exists($path),
            'The invite_token migration must be removed (its work is replaced by the new drop-table migration).'
        );
    }
}
