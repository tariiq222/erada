<?php

namespace App\Modules\OVR\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportableType extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ovr_reportable_types';

    protected $fillable = [
        'organization_id',
        'incident_type_id',
        'name',
        'name_ar',
    ];

    protected $casts = [
        'organization_id' => 'integer',
    ];

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId);
        });
    }
}
