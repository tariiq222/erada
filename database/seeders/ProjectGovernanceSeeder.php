<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\ProjectSetting;
use Illuminate\Database\Seeder;

/**
 * Configures the project-type → governing-department mapping on fresh installs.
 *
 * Members of (the subtree of) the chosen department may create that project
 * type for any department and see all projects of that type org-wide. The
 * mapping is per type because improvement (تحسيني) and development (تطويري)
 * projects are owned by different oversight directorates:
 *
 *   - improvement → QA / Quality / Patient Safety directorate
 *                   (FOCUS-PDCA methodology owner)
 *   - development → Planning & Transformation / PMO directorate
 *                   (PMBOK / portfolio governance owner)
 *
 * Resolution order — for each type independently:
 *
 *   1. Already configured at /api/projects/governing-departments — the seeder
 *      does NOT overwrite the existing per-type mapping for that key.
 *
 *   2. Auto-pick the narrowest dept whose code/name carries the right signal:
 *        improvement → qa / quality / جودة / سلامة
 *        development → pmo / planning / transformation / تخطيط / تحول
 *
 *   3. None found for a given type → log an error specific to that type and
 *      skip the type entirely. The mapping becomes partial instead of silent.
 *
 * ponytail: this seeder used to call setGoverningDepartments($map) and
 * OVERWROTE whatever the admin had wired up via the UI — a deliberate
 * docblock lie ("Idempotent") that hid a destructive re-seed. The fix
 * merges each type's candidate into the existing map so partial manual
 * configuration survives a re-run.
 */
class ProjectGovernanceSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private const TYPE_SIGNALS = [
        'improvement' => ['%qa%', '%quality%', '%جودة%', '%سلامة%'],
        'development' => ['%pmo%', '%planning%', '%transformation%', '%تخطيط%', '%تحول%'],
    ];

    public function run(): void
    {
        $existing = ProjectSetting::getGoverningDepartments();
        $merged = $existing;

        foreach (self::TYPE_SIGNALS as $type => $signals) {
            if (isset($existing[$type])) {
                $this->command->info("Project governing[{$type}] already configured — skipping seed.");

                continue;
            }

            $candidate = $this->findBySignals($signals);

            if ($candidate) {
                $merged[$type] = (int) $candidate->id;
                $this->command->info("Project governing[{$type}] seeded from org tree: {$candidate->code} (id={$candidate->id}).");

                continue;
            }

            $this->command->error(
                "Project governing[{$type}] NOT SET. No matching department "
                .'('.implode(', ', $signals).') found in this org. An admin must '
                .'select one at PUT /api/projects/governing-departments before '
                .'projects of that type gain org-wide governance.'
            );
        }

        // Only persist when at least one slot changed — partial-but-unchanged
        // reseeds stay side-effect-free.
        if ($merged !== $existing) {
            ProjectSetting::setGoverningDepartments($merged);
        }
    }

    /**
     * Find the narrowest department whose code or name matches any of the
     * supplied SQL LIKE patterns (already include the % wildcard).
     *
     * @param  array<int, string>  $signals
     */
    private function findBySignals(array $signals): ?Department
    {
        $query = Department::query()->where('is_active', true);
        $query->where(function ($q) use ($signals) {
            foreach ($signals as $signal) {
                // LOWER(code) LIKE / LOWER(name) LIKE — case-insensitive on
                // Postgres without a citext extension.
                $q->orWhereRaw('LOWER(code) LIKE ?', [$signal])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$signal]);
            }
        });

        return $query->orderByDesc('level')->orderBy('id')->first();
    }
}
