<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationSettings;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_can_read_own_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/organizations/{$org->id}/settings");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['locale_overrides', 'branding_overrides', 'notification_templates']]);
    }

    public function test_get_is_strictly_non_mutating(): void
    {
        // GET must not write any row, must not lock, must not emit an
        // activity-log entry. We assert by snapshotting DB counts before
        // and after the request.
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $auditBefore = ActivityLog::query()->count();
        $settingsBefore = OrganizationSettings::query()->where('organization_id', $org->id)->count();

        $response = $this->getJson("/api/organizations/{$org->id}/settings");

        $response->assertOk();
        $this->assertSame($auditBefore, ActivityLog::query()->count(), 'GET must not write ActivityLog rows.');
        $this->assertSame($settingsBefore, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'GET must not insert a row.');
    }

    public function test_first_put_creates_then_locks(): void
    {
        // On the first PUT, the row does not exist yet. Controller MUST
        // firstOrCreate() so the first PUT succeeds (the previous
        // firstOrFail() 404'd on the first PUT, which was a defect).
        [$org, $actor] = $this->seedOrgSuper();
        $this->assertSame(0, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'precondition: no settings row yet.');
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['primary_color' => '#1F3A8A'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.branding_overrides.primary_color', '#1F3A8A');
        $this->assertSame(1, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'first PUT must firstOrCreate the row.');
    }

    public function test_atomic_upsert_handles_duplicate_unique_key_without_throwing_23505(): void
    {
        // CSD-CA23078-CORE-009 (Task 5 — first-PUT race fix).
        //
        // The controller's "first PUT" path uses
        // `INSERT ... ON CONFLICT (organization_id) DO NOTHING` (atomic
        // upsert) instead of the previous `SELECT FOR UPDATE → create`
        // pattern. That previous pattern raced when two concurrent
        // first-PUTs reached the `create()` call together — the loser's
        // unique-constraint hit raised SQLSTATE 23505, and PostgreSQL
        // marks the WHOLE transaction as in-error state on that single
        // 23505, so the follow-up `SELECT FOR UPDATE` inside the same
        // aborted transaction used to fail with `current transaction is
        // aborted, commands ignored until end of transaction block`.
        //
        // This test exercises the controller's `update()` path
        // directly (via the HTTP seam) to prove the atomic upsert is
        // hit on the SECOND PUT against an organization_id whose row
        // already exists. Without `ON CONFLICT DO NOTHING`, the
        // controller's `INSERT` would raise 23505 on the second PUT;
        // with the clause, the second `INSERT` is a no-op, the
        // follow-up `SELECT FOR UPDATE` finds the existing row, and
        // the deep-merge runs against the committed state from the
        // first PUT.
        //
        // The merge-into-committed-state invariant is the load-bearing
        // signal: the second PUT carries a disjoint key (`logo_path`)
        // that the first PUT did NOT set, while the first PUT set
        // `primary_color` that the second PUT does NOT touch. After
        // both PUTs, BOTH keys must be present on the same row — the
        // merge ran against the first PUT's committed payload, not
        // against a fresh default payload (which would have lost
        // `primary_color`).
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        // First PUT: exercises the controller's INSERT branch (row
        // did not exist before). This sets `primary_color`.
        $first = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['primary_color' => '#FF0000'],
        ]);
        $first->assertOk();
        $this->assertSame(
            1,
            OrganizationSettings::query()->where('organization_id', $org->id)->count(),
            'first PUT must have created the row.'
        );

        // Second PUT: must NOT raise 23505. Without the atomic upsert,
        // the controller's `INSERT` would collide with the existing
        // row's unique key and the WHOLE transaction would be aborted
        // by PostgreSQL. With `ON CONFLICT DO NOTHING`, the INSERT is
        // a no-op and the request succeeds.
        $second = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['logo_path' => '/second-put.svg'],
        ]);
        $second->assertOk();

        // No duplicate row was created: the second PUT's INSERT was a
        // silent no-op (ON CONFLICT DO NOTHING), not a 23505-then-rollback
        // and re-insert dance.
        $this->assertSame(
            1,
            OrganizationSettings::query()->where('organization_id', $org->id)->count(),
            'second PUT must NOT have created a duplicate row (atomic upsert path).'
        );

        // The committed state carries BOTH keys: deep-merge ran against
        // the row the first PUT committed (primary_color survives), AND
        // absorbed the second PUT's disjoint key (logo_path lands). A
        // controller whose atomic upsert silently fell back to a fresh
        // default payload would lose primary_color.
        $second->assertJsonPath('data.branding_overrides.primary_color', '#FF0000');
        $second->assertJsonPath('data.branding_overrides.logo_path', '/second-put.svg');
    }

    public function test_first_put_against_concurrently_committed_row_uses_committed_payload(): void
    {
        // CSD-CA23078-CORE-009 (Task 5 — first-PUT race fix).
        //
        // Proves the controller's deep-merge path is driven by the
        // *committed* row state, not by a default-payload in-memory
        // snapshot. We simulate a concurrent first-PUT winner by
        // raw-inserting a settings row outside the controller (and
        // outside the model's auto-fill path) for the same
        // organization_id the controller is about to PUT against.
        //
        // The controller's atomic upsert is therefore a no-op (the
        // pre-existing row hits the unique-index conflict and the
        // `ON CONFLICT DO NOTHING` makes the INSERT silent), the
        // follow-up `SELECT FOR UPDATE` finds the pre-existing row,
        // and the deep-merge is computed against THAT row's settings,
        // not against the controller's empty default payload. The PUT
        // payload we send is a deep-merge of `notification_templates`
        // (a new top-level key) against the pre-existing branding —
        // both must survive, and the pre-existing `primary_color` must
        // NOT be wiped by the new payload.
        //
        // This is the "retry uses committed row" invariant the
        // previous try/catch-23505-and-re-fetch pattern could not
        // honor in PostgreSQL (the 23505 aborted the transaction
        // before the re-fetch could run).
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        // Pre-insert a "committed by the racing winner" row directly
        // via raw SQL. The committed payload has a primary_color that
        // our PUT does NOT include in its `branding_overrides` map;
        // if the controller falls back to a default payload, the
        // primary_color disappears and the assertion below fires.
        $committedSettings = [
            'locale_overrides' => ['ar' => 'ar-EG', 'en' => 'en-US'],
            'branding_overrides' => ['primary_color' => '#COMMITTED'],
            'notification_templates' => ['welcome' => 'committed welcome'],
        ];
        DB::insert(
            'INSERT INTO organization_settings '
            .'(organization_id, settings, created_by, updated_by, created_at, updated_at) '
            .'VALUES (?, ?::jsonb, ?, NULL, NOW(), NOW())',
            [
                $org->id,
                json_encode($committedSettings, JSON_THROW_ON_ERROR),
                $actor->id,
            ],
        );

        // Our PUT only touches `branding_overrides.logo_path` and a
        // NEW `notification_templates.reminder`. It does NOT touch
        // `branding_overrides.primary_color` (the committed one) and
        // does NOT touch `notification_templates.welcome` (the
        // committed one). Deep merge against the committed row must
        // preserve all three pre-existing keys while adding the two
        // new ones.
        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['logo_path' => '/committed-race.svg'],
            'notification_templates' => ['reminder' => 'new reminder'],
        ]);

        $response->assertOk();

        // Assertion 1: pre-existing primary_color survives — the
        // controller merged against the committed row, not against
        // the default payload.
        $response->assertJsonPath('data.branding_overrides.primary_color', '#COMMITTED');
        // Assertion 2: the new key lands alongside the committed one.
        $response->assertJsonPath('data.branding_overrides.logo_path', '/committed-race.svg');
        // Assertion 3: the pre-existing top-level keys survive — the
        // merge helper is not wiping sibling top-level objects.
        $response->assertJsonPath('data.locale_overrides.ar', 'ar-EG');
        $response->assertJsonPath('data.locale_overrides.en', 'en-US');
        $response->assertJsonPath('data.notification_templates.welcome', 'committed welcome');
        $response->assertJsonPath('data.notification_templates.reminder', 'new reminder');

        // Assertion 4: the row is the pre-existing one (the atomic
        // upsert was a no-op, not a second insert).
        $this->assertSame(
            1,
            OrganizationSettings::query()->where('organization_id', $org->id)->count(),
            'pre-existing row must not be duplicated; the atomic upsert must be a no-op on conflict.'
        );

        // Assertion 5: the audit row carries the committed payload as
        // its `old_values.settings` — confirming the merge was
        // computed against the committed row's `settings` and not
        // against the default payload (which would have shown empty
        // `branding_overrides` in `old_values`).
        $audit = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'audit log row must exist for the PUT.');
        $this->assertSame(
            '#COMMITTED',
            $audit->old_values['settings']['branding_overrides']['primary_color'] ?? null,
            'audit old_values.settings must reflect the COMMITTED row state, not the default payload.'
        );
    }

    public function test_put_performs_deep_merge_across_top_level_objects(): void
    {
        // Seed: locale_overrides has both ar and en; branding_overrides has
        // primary_color; notification_templates has welcome + reminder.
        // PUT only: locale_overrides.ar, branding_overrides.logo_path,
        // notification_templates.welcome. After the PUT, the un-touched
        // keys (locale_overrides.en, branding_overrides.primary_color,
        // notification_templates.reminder) MUST still be present (deep
        // merge, not shallow array_replace).
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => ['ar' => 'ar', 'en' => 'en'],
                'branding_overrides' => ['primary_color' => '#111111'],
                'notification_templates' => [
                    'welcome' => 'old welcome',
                    'reminder' => 'old reminder',
                ],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'locale_overrides' => ['ar' => 'ar-EG'],
            'branding_overrides' => ['logo_path' => '/logo.svg'],
            'notification_templates' => ['welcome' => 'new welcome'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.locale_overrides.ar', 'ar-EG');
        $response->assertJsonPath('data.locale_overrides.en', 'en');
        $response->assertJsonPath('data.branding_overrides.primary_color', '#111111');
        $response->assertJsonPath('data.branding_overrides.logo_path', '/logo.svg');
        $response->assertJsonPath('data.notification_templates.welcome', 'new welcome');
        $response->assertJsonPath('data.notification_templates.reminder', 'old reminder');
    }

    public function test_put_with_empty_object_does_not_wipe_existing_keys(): void
    {
        // Sending an empty `notification_templates => []` MUST NOT wipe
        // the existing notification_templates map. Empty objects are a
        // no-op; explicit nulls on nullable scalar fields clear.
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => [],
                'branding_overrides' => [],
                'notification_templates' => ['welcome' => 'keep me'],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'notification_templates' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.notification_templates.welcome', 'keep me');
    }

    public function test_put_with_null_on_nullable_scalar_clears_the_value(): void
    {
        // `branding_overrides.primary_color => null` is an explicit clear.
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => [],
                'branding_overrides' => ['primary_color' => '#111111'],
                'notification_templates' => [],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['primary_color' => null],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.branding_overrides.primary_color', null);
    }

    public function test_put_emits_activity_log_with_provenance_and_request_id(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);
        $requestId = (string) Str::uuid();

        $response = $this->withHeaders(['X-Request-Id' => $requestId])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#1F3A8A'],
            ]);

        $response->assertOk();

        $audit = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'audit log row must exist for the PUT.');
        $this->assertSame('organization_super_admin', $audit->metadata['provenance'] ?? null);
        $this->assertSame($requestId, $audit->metadata['request_id'] ?? null);
    }

    public function test_put_reuses_cached_response_on_idempotency_key_retry(): void
    {
        // The `idempotency` middleware caches the response by X-Idempotency-Key
        // for state-changing requests. A retry with the same key MUST return
        // the same payload AND MUST NOT write a second ActivityLog row.
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);
        $idempotencyKey = (string) Str::uuid();

        $first = $this->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#1F3A8A'],
            ]);
        $first->assertOk();

        $auditAfterFirst = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->count();

        $second = $this->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#FFFFFF'], // payload differs — idempotency ignores body.
            ]);
        $second->assertOk();
        $this->assertSame(
            $first->json('data.branding_overrides.primary_color'),
            $second->json('data.branding_overrides.primary_color'),
            'retry must return the cached response, not re-execute the PUT.'
        );

        $auditAfterSecond = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->count();
        $this->assertSame($auditAfterFirst, $auditAfterSecond, 'retry must not write a second audit row.');
    }

    public function test_org_super_cannot_read_other_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/organizations/{$otherOrg->id}/settings");

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org read.");
    }

    public function test_org_super_cannot_edit_other_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$otherOrg->id}/settings", [
            'branding_overrides' => ['primary_color' => '#000000'],
        ]);

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org write.");
    }

    public function test_org_super_cannot_use_cluster_tree_capabilities_to_widen(): void
    // Cluster denial: OrgSuper MUST NOT hold any `core.cluster_tree.*`
    // capability. Even after the targeted sweep, OrgSuper pivots on the
    // `Organization` resource must be exactly the curated set (which
    // contains NO `view`/`edit` for Organization — those came from the
    // obsolete mapping alias and were swept in 000022).
    {
        [$org, $actor] = $this->seedOrgSuper();

        $clusterCapabilities = [
            'core.cluster_tree.view',
            'core.cluster_tree.manage',
            'core.cluster_tree.export',
        ];

        $this->assertSame(
            [],
            $this->capabilitiesForUser($actor, $clusterCapabilities),
            'OrgSuper must hold zero core.cluster_tree.* capabilities.'
        );

        // Live pivot audit: there must be no `Organization` × `view`/`edit`
        // pivots on the OrgSuper role.
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $organizationResourceId = \DB::table('authorization_resources')->where('key', Organization::class)->value('id');

        $this->assertNotNull($organizationResourceId, 'precondition: Organization resource row must exist.');

        $viewEditPivots = \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuperRole->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', ['view', 'edit'])
            ->count();

        $this->assertSame(
            0,
            $viewEditPivots,
            'targeted sweep 000022 must have removed every Organization x view/edit pivot on the OrgSuper role.'
        );
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function capabilitiesForUser(User $user, array $capabilities): array
    {
        return array_values(array_intersect($user->canonicalCapabilityNames(), $capabilities));
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        // Seed the role catalog so AccessDecision::can() can resolve the
        // curated OrgSuper capabilities and so the targeted obsolete-pivot
        // sweep has an OrgSuper role + Organization resource to operate on.
        (new RolesAndPermissionsSeeder)->run();

        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
