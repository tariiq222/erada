<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneDeliverable extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'name',
        'description',
        'status',
        'progress',
        'order',
    ];

    protected $casts = [
        'progress' => 'decimal:2',
    ];

    // المرحلة
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    // المشروع (من خلال المرحلة)
    public function project()
    {
        return $this->milestone->project();
    }
}
