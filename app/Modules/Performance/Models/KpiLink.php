<?php

namespace App\Modules\Performance\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiLink extends Model
{
    use HasFactory, LogsActivity, SoftDeletes; // M-11: audit link create/update/delete

    protected $fillable = [
        'kpi_id',
        'linkable_type',
        'linkable_id',
        'relationship_type',
        'weight',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
