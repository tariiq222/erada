<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\RiskManagement\Enums\RiskType as LegacyRiskType;
use Illuminate\Database\Eloquent\Model;

class RiskType extends Model
{
    protected $fillable = [
        'value',
        'label',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    private static ?array $labelCache = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::$labelCache = null);
        static::deleted(fn () => self::$labelCache = null);
    }

    public static function activeOptions(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['value', 'label'])
            ->map(fn (RiskType $type) => [
                'value' => $type->value,
                'label' => $type->label,
            ])
            ->all();
    }

    public static function labelFor(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $labels = self::labelMap();

        if (array_key_exists($value, $labels)) {
            return $labels[$value];
        }

        return LegacyRiskType::tryFrom($value)?->label() ?? $value;
    }

    private static function labelMap(): array
    {
        if (self::$labelCache === null) {
            self::$labelCache = static::query()->pluck('label', 'value')->all();
        }

        return self::$labelCache;
    }
}
