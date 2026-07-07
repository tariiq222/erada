<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskStatusChange extends Model
{
    use HasFactory;

    protected $table = 'risk_status_changes';

    protected $fillable = [
        'risk_id',
        'organization_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
