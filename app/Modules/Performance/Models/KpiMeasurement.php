<?php

namespace App\Modules\Performance\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class KpiMeasurement extends Model
{
    use HasFactory, LogsActivity; // M-11: audit measurement create/update/delete

    protected $fillable = [
        'kpi_id',
        'value',
        'measurement_date',
        'notes',
        'evidence_url',
        'source_type',
        'source_id',
        'recorded_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'measurement_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::created(function (KpiMeasurement $measurement) {
            $measurement->kpi?->updateFromLatestMeasurement();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
