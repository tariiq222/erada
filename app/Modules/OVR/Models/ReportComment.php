<?php

namespace App\Modules\OVR\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportComment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ovr_report_comments';

    protected $fillable = [
        'report_id',
        'user_id',
        'author_name',
        'text',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
