<?php

namespace App\Modules\Projects\Models;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Project settings — global preferences for project-related features.
 *
 * The supervisor_* methods are legacy. The supervisor_id/supervisor_id/sponsor_id columns
 * were removed from the projects table in the project-roles unification; the settings
 * remain as system-wide preferences (e.g., "does the org require a supervisor pick?")
 * but no project actually persists a supervisor. Marked @deprecated for caller awareness;
 * the methods are kept for backward compatibility with admin settings UI.
 */
class ProjectSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
    ];

    /**
     * الحصول على قيمة إعداد معين
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("project_setting_{$key}", 3600, function () use ($key) {
            return static::where('key', $key)->first();
        });

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

    /**
     * تعيين قيمة إعداد
     */
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

        // تحويل القيمة حسب النوع
        $setting->value = match ($setting->type) {
            'json' => is_string($value) ? $value : json_encode($value),
            'boolean' => $value ? 'true' : 'false',
            default => (string) $value,
        };

        $setting->save();

        // مسح الكاش
        Cache::forget("project_setting_{$key}");
    }

    /**
     * الحصول على الأقسام المسموح لها بالإشراف
     */
    public static function getSupervisorAllowedDepartments(): array
    {
        return static::getValue('supervisor_allowed_departments', []);
    }

    /**
     * تعيين الأقسام المسموح لها بالإشراف
     */
    public static function setSupervisorAllowedDepartments(array $departmentIds): void
    {
        static::setValue('supervisor_allowed_departments', $departmentIds, 'json');
    }

    /**
     * هل تحديد المشرف إلزامي؟
     */
    public static function isSupervisorRequired(): bool
    {
        return static::getValue('supervisor_required', false);
    }

    /**
     * تعيين إلزامية المشرف
     */
    public static function setSupervisorRequired(bool $required): void
    {
        static::setValue('supervisor_required', $required, 'boolean');
    }

    /**
     * The configurable "governing department per project type" mapping.
     *
     * Shape: ['improvement' => <departmentId>, 'development' => <departmentId>]. Members
     * of (the subtree of) a type's governing department may create that type for ANY
     * department and see every project of that type org-wide. The governor of the
     * 'development' type is the PMO and oversees the whole project portfolio (all types).
     *
     * @return array<string, int>
     */
    public static function getGoverningDepartments(): array
    {
        // Reads from the unified governance_rules table (project rows are keyed by
        // resource_subtype = the project type). The legacy setting was global (one
        // map system-wide), so a single org holds the project rules; merge across
        // orgs — dept ids are org-unique so there is no collision.
        return GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_PROJECT)
            ->whereNotNull('resource_subtype')
            ->whereNotNull('governing_unit_id')
            ->pluck('governing_unit_id', 'resource_subtype')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Persist the governing-department-per-type mapping.
     *
     * @param  array<string, int|null>  $map
     */
    public static function setGoverningDepartments(array $map): void
    {
        // Existing project subtype rules that are absent from (or nulled in) the new
        // map are cleared; a department resolves its own organization_id.
        $incoming = [];
        foreach ($map as $type => $departmentId) {
            if ($departmentId !== null && $departmentId !== '') {
                $incoming[(string) $type] = (int) $departmentId;
            }
        }

        $existingTypes = GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_PROJECT)
            ->whereNotNull('resource_subtype')
            ->pluck('resource_subtype')
            ->all();

        foreach (array_diff($existingTypes, array_keys($incoming)) as $staleType) {
            GovernanceRule::query()
                ->where('resource_type', GovernanceRule::TYPE_PROJECT)
                ->where('resource_subtype', $staleType)
                ->delete();
        }

        foreach ($incoming as $type => $departmentId) {
            $orgId = Department::query()->whereKey($departmentId)->value('organization_id');
            $orgId = $orgId === null ? null : (int) $orgId;

            // Single-governor-per-type invariant (legacy map held one dept per type):
            // drop any existing rule for this type regardless of org, then set it.
            GovernanceRule::query()
                ->where('resource_type', GovernanceRule::TYPE_PROJECT)
                ->where('resource_subtype', $type)
                ->delete();

            GovernanceRule::setGoverningUnit($orgId, GovernanceRule::TYPE_PROJECT, $type, $departmentId, ['projects.*']);
        }

        GovernanceRule::clearCache();
    }

    /**
     * The governing department id for a given project type, or null when unset.
     */
    public static function getGoverningDepartmentForType(?string $type): ?int
    {
        if ($type === null || $type === '') {
            return null;
        }

        return static::getGoverningDepartments()[$type] ?? null;
    }
}
