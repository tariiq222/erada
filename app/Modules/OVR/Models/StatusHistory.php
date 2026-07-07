<?php

namespace App\Modules\OVR\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ovr_status_history';

    protected $fillable = [
        'report_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'report_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
