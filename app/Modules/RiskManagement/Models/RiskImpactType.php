<?php

namespace App\Modules\RiskManagement\Models;

use Illuminate\Database\Eloquent\Model;

class RiskImpactType extends Model
{
    protected $fillable = [
        'value',
        'label',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'value' => 'string',
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
            ->orderBy('value')
            ->get(['value', 'label'])
            ->map(fn (RiskImpactType $impactType) => [
                'value' => $impactType->value,
                'label' => $impactType->label,
            ])
            ->all();
    }

    public static function labelFor(int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = (string) $value;
        $labels = self::labelMap();

        if (array_key_exists($key, $labels)) {
            return $labels[$key];
        }

        return $key;
    }

    private static function labelMap(): array
    {
        if (self::$labelCache === null) {
            self::$labelCache = static::query()->pluck('label', 'value')->all();
        }

        return self::$labelCache;
    }
}
