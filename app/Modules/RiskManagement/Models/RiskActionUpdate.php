<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskActionUpdate extends Model
{
    use HasFactory;

    protected $table = 'risk_action_updates';

    protected $fillable = [
        'risk_action_id',
        'organization_id',
        'user_id',
        'progress_pct',
        'status',
        'notes',
    ];

    protected $casts = [
        'progress_pct' => 'integer',
        'status' => RiskActionStatus::class,
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(RiskAction::class, 'risk_action_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
