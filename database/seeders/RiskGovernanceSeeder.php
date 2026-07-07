<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\RiskSetting;
use Illuminate\Database\Seeder;

/**
 * Configures the Risk governing department on fresh installs and migrations.
 *
 * Members of (the subtree of) this department may create risks for any
 * department and see every risk in the organization
 * (RiskResource / RiskAuthorizationService — same contract as OVR).
 *
 * Resolution order — first match wins:
 *
 *   1. Already configured via /api/risk-management/settings/governing-department.
 *      An admin may have picked the right department; the seeder does NOT
 *      overwrite it. This is the runtime knob.
 *
 *   2. Auto-pick the narrowest department whose code or name carries the
 *      "Quality / Patient Safety" signal: code/name LIKE %qa% / %quality% /
 *      %جودة% / %سلامة%. Highest level id wins so a dedicated unit beats the
 *      parent directorate.
 *
 *   3. None found → log an error and stop. The settings endpoint exposes
 *      the picker an admin uses once the right department exists. The
 *      seeder's job is to NEVER silently disable org-wide oversight.
 *
 * ponytail: this seeder used to hard-code AED-QA and silently no-op when the
 * dept didn't exist — the same anti-pattern as the original
 * OvrGovernanceSeeder. Three seeders in this repo share the same conceptual
 * role (org-wide governance for one module), so the resolution strategy
 * should look identical across them. Anything that diverges between modules
 * here is by design; anything identical is the engine contract.
 */
class RiskGovernanceSeeder extends Seeder
{
    public function run(): void
    {
        if (RiskSetting::getGoverningDepartmentId() !== null) {
            $this->command->info('Risk governing department already configured — skipping seed.');

            return;
        }

        $governing = $this->findQualityDepartment();

        if ($governing) {
            RiskSetting::setGoverningDepartmentId((int) $governing->id);
            $this->command->info("Risk governing department seeded from org tree: {$governing->code} (id={$governing->id}).");

            return;
        }

        $this->command->error(
            'Risk governing department NOT SET. No quality / patient-safety department was found in this org. '
            .'An admin must select one at PUT /api/risk-management/settings/governing-department before '
            .'risk governance is enabled. Until then risks are visible only via per-row role grants.'
        );
    }

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
            ->orderByDesc('level')
            ->orderBy('id')
            ->first();
    }
}
