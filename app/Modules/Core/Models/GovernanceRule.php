<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unified governance rule — the single source of truth for "which department
 * governs a resource type org-wide" (ADR-UNIFIED-ROLE-ACCESS, Phase 1).
 *
 * Replaces RiskSetting/OvrSetting/ProjectSetting governing-department settings.
 * The per-module authorization services read the governing unit from here via
 * the thin getters on those Setting models, so decision logic stays unchanged.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $resource_type
 * @property string|null $resource_subtype
 * @property int|null $governing_unit_id
 * @property array<int, string> $capabilities
 * @property bool $applies_to_children
 */
class GovernanceRule extends Model
{
    public const TYPE_RISK = 'risk';

    public const TYPE_OVR = 'ovr';

    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'organization_id',
        'resource_type',
        'resource_subtype',
        'governing_unit_id',
        'capabilities',
        'applies_to_children',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'governing_unit_id' => 'integer',
        'capabilities' => 'array',
        'applies_to_children' => 'boolean',
    ];

    /**
     * Per-request memoization of resolver lookups, keyed by "org:type:subtype".
     * Governing rules are read on every list/create decision (LR-104), so we must
     * not re-query per record. Invalidated on any write via clearCache().
     *
     * @var array<string, int|null>
     */
    protected static array $resolveCache = [];

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    public static function clearCache(): void
    {
        static::$resolveCache = [];
    }

    /**
     * The governing department id for a resource type within an organization.
     *
     * A subtype (e.g. a project type) falls back to the NULL-subtype rule when no
     * subtype-specific rule exists. Returns null when nothing governs it.
     */
    public static function governingUnitId(?int $organizationId, string $resourceType, ?string $subtype = null): ?int
    {
        $key = ($organizationId ?? '').':'.$resourceType.':'.($subtype ?? '');
        if (array_key_exists($key, static::$resolveCache)) {
            return static::$resolveCache[$key];
        }

        // Subtype-specific rule first, then the NULL-subtype (all-subtypes) rule.
        $rule = static::query()
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id'), fn ($q) => $q->where('organization_id', $organizationId))
            ->where('resource_type', $resourceType)
            ->when(
                $subtype !== null && $subtype !== '',
                fn ($q) => $q->where(fn ($w) => $w->where('resource_subtype', $subtype)->orWhereNull('resource_subtype'))
                    ->orderByRaw('resource_subtype IS NULL'), // non-null subtype ranks before NULL fallback
                fn ($q) => $q->whereNull('resource_subtype'),
            )
            ->first();

        return static::$resolveCache[$key] = $rule?->governing_unit_id;
    }

    /**
     * The governing department id per subtype for a resource type within an org.
     * Only rows with a non-null subtype and a set governing unit are returned.
     *
     * @return array<string, int>
     */
    public static function subtypeMap(int $organizationId, string $resourceType): array
    {
        return static::query()
            ->where('organization_id', $organizationId)
            ->where('resource_type', $resourceType)
            ->whereNotNull('resource_subtype')
            ->whereNotNull('governing_unit_id')
            ->pluck('governing_unit_id', 'resource_subtype')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Upsert (or clear, when $governingUnitId is null) the rule for a scope.
     * Clearing removes the row so an "unset governor" leaves no rule at all.
     *
     * @param  array<int, string>  $capabilities
     */
    public static function setGoverningUnit(
        ?int $organizationId,
        string $resourceType,
        ?string $subtype,
        ?int $governingUnitId,
        array $capabilities = [],
    ): void {
        $where = [
            'organization_id' => $organizationId,
            'resource_type' => $resourceType,
            'resource_subtype' => $subtype,
        ];

        if ($governingUnitId === null) {
            static::scopeQuery($organizationId, $resourceType, $subtype)->delete();
            static::clearCache();

            return;
        }

        // updateOrCreate can't match on NULL columns via a plain equality array, so
        // resolve the existing row through null-safe scoping first.
        $existing = static::scopeQuery($organizationId, $resourceType, $subtype)->first();
        if ($existing) {
            $existing->update([
                'governing_unit_id' => $governingUnitId,
                'capabilities' => $capabilities,
                'applies_to_children' => true,
            ]);
        } else {
            static::query()->create(array_merge($where, [
                'governing_unit_id' => $governingUnitId,
                'capabilities' => $capabilities,
                'applies_to_children' => true,
            ]));
        }
        static::clearCache();
    }

    /**
     * Null-safe scope for the (org, type, subtype) triple.
     */
    protected static function scopeQuery(?int $organizationId, string $resourceType, ?string $subtype)
    {
        return static::query()
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id'), fn ($q) => $q->where('organization_id', $organizationId))
            ->where('resource_type', $resourceType)
            ->when($subtype === null, fn ($q) => $q->whereNull('resource_subtype'), fn ($q) => $q->where('resource_subtype', $subtype));
    }
}
