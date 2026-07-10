<?php

namespace App\Modules\OVR\Models;

use Database\Factories\IncidentTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    use HasFactory, HasUuids;

    /**
     * Point the Eloquent factory resolver at the model's dedicated factory class.
     * Laravel's default resolver expects Database\Factories\<FQCN>Factory, which
     * for a model under App\Modules\OVR\Models\IncidentType would map to a
     * non-existent Database\Factories\App\Modules\OVR\Models\IncidentTypeFactory.
     * The actual factory lives at database/factories/IncidentTypeFactory, so we
     * override here. Mirrors the pattern in App\Modules\Core\Models\User.
     */
    protected static function newFactory(): IncidentTypeFactory
    {
        return IncidentTypeFactory::new();
    }

    protected $table = 'ovr_incident_types';

    protected $fillable = [
        'organization_id',
        'name',
        'name_ar',
        'is_active',
        'requires_reportable_type',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'is_active' => 'boolean',
        'requires_reportable_type' => 'boolean',
    ];

    public function reportableTypes(): HasMany
    {
        return $this->hasMany(ReportableType::class, 'incident_type_id');
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId);
        });
    }
}
