<?php

namespace App\Modules\OVR\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IncidentParticipant — an employee invited to an incident report.
 *
 * A participant may belong to any department; the invitation grants them
 * visibility of the report (see IncidentReport::scopeVisibleTo) regardless of
 * their department-scoped OVR roles.
 */
class IncidentParticipant extends Model
{
    protected $table = 'ovr_incident_participants';

    protected $fillable = [
        'incident_report_id',
        'user_id',
        'invited_by',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
