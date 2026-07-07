<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskAlertType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAlert extends Model
{
    use HasFactory;

    protected $table = 'risk_alerts';

    protected $fillable = [
        'risk_id',
        'risk_action_id',
        'risk_assessment_id',
        'organization_id',
        'type',
        'payload',
        'sent_to',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'type' => RiskAlertType::class,
        'payload' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(RiskAction::class, 'risk_action_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_to');
    }
}
