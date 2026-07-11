# Organization Authorization Release 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the verified enrollment, cross-tenant reference, stale-role, organizationless-grant, import-read, and attachment-upload vulnerabilities.

**Architecture:** An invitation server-side binds registration to organization context. The project mutation service validates all user references before writing relationships. The engine and route middleware remain the final authorization boundaries.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, Sanctum, PHPUnit 11.

## Global Constraints

- Never modify an applied migration; introduce a new PostgreSQL migration where storage is required.
- Use canonical `Capability` values and `AccessDecision::can`.
- Preserve Sanctum’s cookie response only for valid invite consumption.
- Each behavior follows red, green, focused verification, and a scoped commit.

---

### Task 1: Invitation-bound registration

**Files:**
- Create: `database/migrations/2026_07_11_120000_create_organization_registration_invitations_table.php`
- Create: `app/Modules/Core/Models/OrganizationRegistrationInvitation.php`
- Create: `app/Modules/Core/Services/OrganizationRegistrationInvitationService.php`
- Create: `tests/Feature/Core/RegistrationInvitationTest.php`
- Modify: `app/Modules/Core/Http/Controllers/RegistrationController.php`

**Interfaces:**
- Produces: `OrganizationRegistrationInvitationService::consume(string $token, string $email): OrganizationRegistrationInvitation`.
- Preserves: `POST /api/register` and the HttpOnly cookie success response.

- [ ] **Step 1: Write failing tests.**

```php
$this->postJson('/api/register', [
    'name' => 'Intruder', 'email' => 'intruder@example.test',
    'password' => 'password123', 'password_confirmation' => 'password123',
    'organization_id' => $organization->id,
])->assertUnprocessable()->assertJsonValidationErrors(['invite_token']);

$this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
```

- [ ] **Step 2: Verify the test is red.**

Run: `php artisan test tests/Feature/Core/RegistrationInvitationTest.php`
Expected: failure because the current controller accepts organization identifiers and no invite.

- [ ] **Step 3: Add one-time invitation storage and consume service.**

```php
$invite = OrganizationRegistrationInvitation::query()
    ->where('token_hash', hash('sha256', $token))
    ->where('email', $email)
    ->whereNull('consumed_at')
    ->where('expires_at', '>', now())
    ->lockForUpdate()
    ->firstOrFail();
```

The new migration stores `organization_id`, optional `department_id`, email, SHA-256 token hash, expiry, consumption timestamp, and inviter.

- [ ] **Step 4: Change registration to require and atomically consume `invite_token`.**

```php
$invite = $service->consume($validated['invite_token'], $validated['email']);
$user = User::create([
    'organization_id' => $invite->organization_id,
    'department_id' => $invite->department_id,
    'is_active' => true,
    'registration_status' => 'approved',
]);
```

Remove client-controlled `organization_id` and `department_id` from accepted registration fields.

- [ ] **Step 5: Add valid, expired, consumed, and email-mismatch controls; rerun red test.**

Run: `php artisan test tests/Feature/Core/RegistrationInvitationTest.php`
Expected: PASS.

### Task 2: Project user-reference floor

**Files:**
- Create: `app/Modules/Projects/Services/ProjectUserReferenceValidator.php`
- Create: `tests/Feature/Projects/ProjectUserReferenceIsolationTest.php`
- Modify: `app/Modules/Projects/Services/ProjectCrudService.php`

**Interfaces:**
- Produces: `ProjectUserReferenceValidator::assertBelongToOrganization(array $payload, int $organizationId): void`.
- Consumes: manager, team member, task assignee, and stakeholder user identifiers before project writes.

- [ ] **Step 1: Write a failing cross-organization update test.**

```php
$this->actingAs($managerA)->putJson("/api/projects/{$projectA->id}", [
    'team_members' => [['user_id' => $userB->id, 'role' => 'member']],
])->assertUnprocessable()->assertJsonValidationErrors(['team_members.0.user_id']);

$this->assertDatabaseMissing('model_has_scoped_roles', [
    'model_id' => $userB->id, 'scope_id' => $projectA->id,
]);
```

- [ ] **Step 2: Verify it is red.**

Run: `php artisan test tests/Feature/Projects/ProjectUserReferenceIsolationTest.php`

- [ ] **Step 3: Add a service validator and invoke it before every relationship mutation.**

```php
$ids = collect($payload['team_members'] ?? [])->pluck('user_id')
    ->merge(collect($payload['tasks'] ?? [])->pluck('assigned_to'))
    ->merge(collect($payload['stakeholders'] ?? [])->pluck('user_id'))
    ->filter()->map(fn (mixed $id) => (int) $id)->unique();

if (User::whereIn('id', $ids)->where('organization_id', $organizationId)->count() !== $ids->count()) {
    throw ValidationException::withMessages(['team_members' => 'All referenced users must belong to the project organization.']);
}
```

- [ ] **Step 4: Add same-organization control and run the suite.**

Run: `php artisan test tests/Feature/Projects/ProjectUserReferenceIsolationTest.php`
Expected: PASS.

### Task 3: Fail closed at the authorization engine

**Files:**
- Modify: `app/Modules/Core/Authorization/AccessDecision.php`
- Modify: `app/Modules/Core/Models/ScopedRoleDefinition.php`
- Modify: `tests/Feature/Authorization/RoleControllerUnifiedSourceTest.php`
- Create: `tests/Feature/Authorization/OrganizationlessCapabilityDenyTest.php`

- [ ] **Step 1: Write failing tests for inactive definitions and organizationless target-free grants.**

```php
$definition->update(['is_active' => false]);
$this->assertFalse(AccessDecision::can($assignedUser->fresh(), Capability::PROJECTS_VIEW, $project));

$organizationless->syncRoles('admin');
$this->assertFalse(AccessDecision::can($organizationless, Capability::ATTACHMENTS_UPLOAD));
```

- [ ] **Step 2: Verify both tests are red.**

Run: `php artisan test tests/Feature/Authorization/RoleControllerUnifiedSourceTest.php tests/Feature/Authorization/OrganizationlessCapabilityDenyTest.php`

- [ ] **Step 3: Add explicit fail-closed rules.**

```php
if ($target === null && $user->organization_id === null) {
    return static::trace(false, 'organization_required', 'non-system user has no organization context');
}
```

Restrict definition lookup to `is_active = true` and retain an explicit inactive-definition guard in capability evaluation.

- [ ] **Step 4: Run the focused authorization suite and super-admin controls.**

Run: `php artisan test tests/Feature/Authorization/RoleControllerUnifiedSourceTest.php tests/Feature/Authorization/OrganizationlessCapabilityDenyTest.php`
Expected: PASS.

### Task 4: Guard import reads and attachment uploads

**Files:**
- Modify: `app/Modules/Surveys/Routes/api.php`
- Modify: `app/Modules/Surveys/Http/Resources/DataImportRequestResource.php`
- Modify: `app/Modules/Shared/Http/Controllers/UploadController.php`
- Create: `tests/Feature/Api/Surveys/DataImportReadAuthorizationTest.php`
- Modify: `tests/Feature/Shared/UploadControllerTest.php`

- [ ] **Step 1: Write failing HTTP tests.**

```php
$this->actingAs($member)->getJson('/api/data-imports')->assertForbidden();
$this->actingAs($member)->postJson('/api/upload/attachment', [
    'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
])->assertForbidden();
```

- [ ] **Step 2: Verify they are red.**

Run: `php artisan test tests/Feature/Api/Surveys/DataImportReadAuthorizationTest.php tests/Feature/Shared/UploadControllerTest.php`

- [ ] **Step 3: Apply canonical guards and target-only attachment storage.**

```php
Route::get('/', [DataImportController::class, 'index'])
    ->middleware('engine_capability:'.Capability::SURVEYS_REVIEW_DATA_IMPORTS);

if (! AccessDecision::can($request->user(), Capability::ATTACHMENTS_UPLOAD)) {
    abort(403, 'ليس لديك صلاحية رفع الملفات');
}
```

Require exactly one of project or task, resolve it server-side, authorize its project through the engine, and remove the client-controlled folder path.

- [ ] **Step 4: Add authorized controls and run the suite.**

Run: `php artisan test tests/Feature/Api/Surveys/DataImportReadAuthorizationTest.php tests/Feature/Shared/UploadControllerTest.php`
Expected: PASS.

### Task 5: Verification

**Files:**
- Modify: `docs/authz/2026-07-11-organization-permissions-remediation-design.md`

- [ ] **Step 1: Run all focused regression suites.**

Run: `php artisan test tests/Feature/Core/RegistrationInvitationTest.php tests/Feature/Projects/ProjectUserReferenceIsolationTest.php tests/Feature/Authorization/RoleControllerUnifiedSourceTest.php tests/Feature/Authorization/OrganizationlessCapabilityDenyTest.php tests/Feature/Api/Surveys/DataImportReadAuthorizationTest.php tests/Feature/Shared/UploadControllerTest.php`

- [ ] **Step 2: Run quality gates.**

Run: `./vendor/bin/pint --test <touched-php-files> && composer phpstan && git diff --check`
Expected: exit code `0`.

- [ ] **Step 3: Re-check each exploit against final control flow and commit only scoped files.**
