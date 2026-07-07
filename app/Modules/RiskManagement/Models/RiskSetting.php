<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Risk module key/value settings (mirrors ProjectSetting).
 *
 * The "governing department for risks" now lives in the unified governance_rules
 * table (ADR-UNIFIED-ROLE-ACCESS, Phase 1). The getter/setter below are thin
 * read/write shims over GovernanceRule so RiskAuthorizationService keeps working
 * unchanged. A single governor applies to all risk types.
 */
class RiskSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("risk_setting_{$key}", 3600, fn () => static::where('key', $key)->first());

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'json' => json_decode($setting->value, true) ?? $default,
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            default => $setting->value,
        };
    }

    public static function setValue(string $key, mixed $value, ?string $type = null, ?string $description = null): void
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            $setting = new static;
            $setting->key = $key;
            $setting->type = $type ?? 'string';
            if ($description) {
                $setting->description = $description;
            }
        }

        $setting->value = match ($setting->type) {
            'json' => is_string($value) ? $value : json_encode($value),
            'boolean' => $value ? 'true' : 'false',
            default => (string) $value,
        };

        $setting->save();
        Cache::forget("risk_setting_{$key}");
    }

    /**
     * The configured governing department id for risks, or null when unset.
     *
     * Reads from the unified governance_rules table. The legacy setting was global
     * (one governor system-wide), so a single risk rule exists; return its unit.
     */
    public static function getGoverningDepartmentId(): ?int
    {
        return GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_RISK)
            ->whereNull('resource_subtype')
            ->value('governing_unit_id');
    }

    /**
     * Set (or clear, when null) the governing department for risks.
     *
     * The organization is resolved from the target department (a department belongs
     * to exactly one org), preserving the legacy single-governor semantics.
     */
    public static function setGoverningDepartmentId(?int $departmentId): void
    {
        // Clearing: drop the risk rule everywhere (legacy = single global governor).
        if ($departmentId === null) {
            GovernanceRule::query()
                ->where('resource_type', GovernanceRule::TYPE_RISK)
                ->whereNull('resource_subtype')
                ->delete();
            GovernanceRule::clearCache();

            return;
        }

        $orgId = Department::query()->whereKey($departmentId)->value('organization_id');
        $orgId = $orgId === null ? null : (int) $orgId;

        // Single-governor invariant (legacy = one global risk governor): drop any
        // existing risk rule regardless of org, then write the new one.
        GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_RISK)
            ->whereNull('resource_subtype')
            ->delete();

        GovernanceRule::setGoverningUnit($orgId, GovernanceRule::TYPE_RISK, null, $departmentId, ['risks.*']);
    }
}
