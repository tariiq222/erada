<?php

use App\Modules\Core\Authorization\Capability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (ADR-UNIFIED-ROLE-ACCESS): express every scoped role definition's
 * granular grants as an explicit permissions[] of module.action, retiring the
 * boolean flag columns can_edit / can_delete / can_view_all / can_manage_members
 * / can_view_confidential.
 *
 * This migration MERGES the capabilities each flag currently expands to (in the
 * engine's AccessDecision::capabilityMatchesFlags) into the definition's
 * permissions[]. After this runs, permissions[] alone fully represents each
 * definition's granular grant set — so the follow-up drop migration removes the
 * columns without changing any user's effective grants.
 *
 * is_admin_role is deliberately NOT expanded: it stays as an explicit
 * "grants ALL capabilities" shortcut in the engine (expanding it into an
 * enumerated list would silently exclude any capability added later).
 *
 * Idempotent: capabilities are merged with array_unique, so re-running adds
 * nothing new.
 *
 * The expansion below mirrors the exact pre-change engine logic:
 *   - can_edit             => every capability whose action is edit / update
 *   - can_delete           => every capability whose action is delete / remove
 *   - can_view_all         => every capability whose action is view / view_all / view_reports
 *   - can_manage_members   => every capability whose action is manage_members / assign_roles
 *   - can_view_confidential=> the single OVR need-to-know capability ovr.view_confidential
 *     (this flag was consumed at the OVR policy layer, not by capabilityMatchesFlags,
 *      so it maps to exactly one capability rather than an action-suffix family)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to do if the flag columns were already dropped (idempotent /
        // fresh installs where the drop migration ran in the same batch order).
        if (! Schema::hasColumn('scoped_role_definitions', 'can_edit')) {
            return;
        }

        $flagCapabilities = $this->flagCapabilityMap();

        DB::table('scoped_role_definitions')
            ->orderBy('id')
            ->each(function ($row) use ($flagCapabilities) {
                $permissions = $this->decodePermissions($row->permissions);

                foreach ($flagCapabilities as $flag => $capabilities) {
                    if ((bool) ($row->$flag ?? false)) {
                        $permissions = array_merge($permissions, $capabilities);
                    }
                }

                $permissions = array_values(array_unique($permissions));

                DB::table('scoped_role_definitions')
                    ->where('id', $row->id)
                    ->update(['permissions' => json_encode($permissions)]);
            });
    }

    /**
     * Down is a no-op: the merge is additive and lossless (the flags remain on
     * the row until the follow-up drop migration). Reversing would require
     * distinguishing migration-added capabilities from originally-listed ones,
     * which is not recoverable — and the drop migration owns the schema reversal.
     */
    public function down(): void
    {
        // Intentionally empty. See docblock.
    }

    /**
     * The exact flag -> capabilities expansion. can_edit/can_delete/can_view_all/
     * can_manage_members expand by action suffix across ALL modules (the engine's
     * capabilityMatchesFlags matched on the action, not the module). can_view_confidential
     * maps to the single ovr.view_confidential capability.
     *
     * @return array<string, array<int, string>>
     */
    private function flagCapabilityMap(): array
    {
        $byAction = function (array $actions): array {
            return array_values(array_filter(
                Capability::all(),
                function (string $capability) use ($actions) {
                    $action = str_contains($capability, '.')
                        ? substr($capability, strrpos($capability, '.') + 1)
                        : $capability;

                    return in_array($action, $actions, true);
                }
            ));
        };

        return [
            'can_edit' => $byAction(['edit', 'update']),
            'can_delete' => $byAction(['delete', 'remove']),
            'can_view_all' => $byAction(['view', 'view_all', 'view_reports']),
            'can_manage_members' => $byAction(['manage_members', 'assign_roles']),
            'can_view_confidential' => [Capability::OVR_VIEW_CONFIDENTIAL],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function decodePermissions($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
