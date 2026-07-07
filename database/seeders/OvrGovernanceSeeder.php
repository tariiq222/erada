<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\OvrSetting;
use Illuminate\Database\Seeder;

/**
 * Configures the OVR governing department on fresh installs and migrations.
 *
 * Members of (the subtree of) this department may create incident reports for
 * any department and see every report in the organization
 * (IncidentReport::scopeVisibleTo + OvrAuthorizationService::governs).
 *
 * Resolution order — first match wins:
 *
 *   1. Already configured via /api/ovr/settings/governing-department.
 *      An admin may have picked the right department; the seeder does NOT
 *      overwrite it. This is the runtime knob.
 *
 *   2. Auto-pick from the org tree. The seeder looks for the most specific
 *      department whose code OR name signals "Quality / Patient Safety":
 *      code LIKE '%qa%' / '%quality%', name LIKE '%جودة%' / '%سلامة%'.
 *      Narrowest (highest level id) wins so a dedicated patient-safety unit
 *      beats the parent Quality directorate.
 *
 *   3. None found → log an error and stop. The OVR settings endpoint exposes
 *      the picker that an admin uses once the right department exists. The
 *      seeder's job is to NEVER silently disable org-wide oversight.
 *
 * ponytail: this whole class used to be a fallback chain over hard-coded
 * dept codes (QA-SAFETY → AED-QA). That assumed every deployment names
 * things the same way, which is wrong in a multi-tenant codebase. We now
 * only ship the resolution strategy; the discovery is org-tree-shape-aware.
 *
 * Operators picking a governing dept is a one-off deployment decision and
 * belongs in the admin UI (PUT /api/ovr/settings/governing-department),
 * NOT in .env.
 */
class OvrGovernanceSeeder extends Seeder
{
    public function run(): void
    {
        // Respect an existing admin override.
        if (OvrSetting::getGoverningDepartmentId() !== null) {
            $this->command->info('OVR governing department already configured — skipping seed.');

            return;
        }

        $governing = $this->findQualityDepartment();

        if ($governing) {
            OvrSetting::setGoverningDepartmentId((int) $governing->id);
            $this->command->info("OVR governing department seeded from org tree: {$governing->code} (id={$governing->id}).");

            return;
        }

        $this->command->error(
            'OVR governing department NOT SET. No quality / patient-safety department was found in this org. '
            .'An admin must select one at PUT /api/ovr/settings/governing-department before '
            .'OvrAuthorizationService::governs() returns true for any user. Until then the '
            .'per-row visibility filter is in effect org-wide.'
        );
    }

    /**
     * Find the narrowest quality / patient-safety department in the org tree.
     * Narrowest first so a dedicated unit beats its parent directorate.
     */
    private function findQualityDepartment(): ?Department
    {
        return Department::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw('LOWER(code) LIKE ?', ['%qa%'])
                    ->orWhereRaw('LOWER(code) LIKE ?', ['%quality%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%جودة%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%سلامة%']);
            })
            ->orderByDesc('level')  // numerically largest level = deepest in tree
            ->orderBy('id')         // stable tiebreaker when multiple match
            ->first();
    }
}
