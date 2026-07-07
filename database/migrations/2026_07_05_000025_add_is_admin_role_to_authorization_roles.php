<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1.4a -- add a per-role `is_admin_role` boolean column to
 * `authorization_roles`.
 *
 * Background:
 *   The legacy engine honored `scoped_role_definitions.is_admin_role` as
 *   an explicit shortcut: an `is_admin_role = true` definition grants ALL
 *   capabilities for the role (see AccessDecision::definitionGrantsCapability
 *   around line 783). The unified engine's new path
 *   (hasNewPermission, AccessDecision around line 1095) does not yet have
 *   the same shortcut -- it answers "yes" only when an
 *   `authorization_role_permissions` pivot matches (resource_id, action).
 *
 *   The 000010 / 000021 backfills materialized scoped roles onto
 *   `authorization_roles` but stripped the `is_admin_role` semantics: an
 *   admin-role user's NEW-path grants are limited to the per-capability
 *   pivots the backfill wrote. A future code path that bypasses the
 *   legacy `whyCan()` walk and reads the new path alone would lose the
 *   admin shortcut, so this slice ports the flag forward so the new path
 *   can honor it the same way.
 *
 *   The companion migration `2026_07_05_000026_backfill_authorization_roles_is_admin_role`
 *   reads the source `scoped_role_definitions` row keyed by role_key and
 *   writes the flag onto the matching `authorization_roles` row. After
 *   that, the new path's admin gate in `hasNewPermission` can honor the
 *   shortcut through the same assignment/scope gate the rest of the new
 *   path uses.
 *
 * Schema:
 *   - `is_admin_role` is a NOT NULL boolean with DEFAULT false. NOT NULL
 *     because the value is always meaningful (it is the legacy behavior
 *     of "grants all caps"); DEFAULT false because the column is non-
 *     widening -- any new row that does not opt in must default to the
 *     safe (non-admin) answer.
 *
 * Constraints honored (per AGENTS.md + AUTHZ-DECISIONS.md):
 *   - PostgreSQL only. SQLite/in-memory are NOT supported.
 *   - OVR confidential is NOT widened. The companion engine change in
 *     `AccessDecision::hasNewPermission` keeps the existing
 *     `can_view_confidential` carve-out: is_admin_role=true still does
 *     NOT grant `Capability::OVR_CONFIDENTIAL`. The carve-out lives in
 *     the engine, not this column -- the column itself is just a flag.
 *   - Backward-compatible additive migration. Existing rows keep their
 *     pivot-row grants until the engine change in 2.1.4a consults this
 *     column.
 *   - Phase 2.1.4a does NOT touch `scoped_role_definitions.is_admin_role`
 *     or `permission_audits`. Spatie / legacy tables are preserved.
 *
 * Safe to run twice: up() uses hasColumn / set schema-level guards so a
 * second up() is a no-op (the column is left intact).
 *
 * down():
 *   Drops the column. The 000026 backfill migration's down() does NOT
 *   drop this column (it is owned by 000025), so operators can roll back
 *   the backfill without losing the column or re-running 000025.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('authorization_roles')) {
            return;
        }

        if (! Schema::hasColumn('authorization_roles', 'is_admin_role')) {
            Schema::table('authorization_roles', function (Blueprint $table) {
                // NOT NULL boolean with DEFAULT false. The default makes
                // the column non-widening for every existing row --
                // phased deployments see "non-admin" semantics until
                // 000026 explicitly flips a row's flag. A row that's
                // still false after 000026 means the source legacy
                // definition also had is_admin_role=false (or there was
                // no matching source definition).
                $table->boolean('is_admin_role')->default(false)->after('label');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('authorization_roles')) {
            return;
        }

        if (Schema::hasColumn('authorization_roles', 'is_admin_role')) {
            Schema::table('authorization_roles', function (Blueprint $table) {
                $table->dropColumn('is_admin_role');
            });
        }
    }
};
