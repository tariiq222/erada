<?php

namespace App\Modules\Projects\Models;

use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Tasks\Models\Task;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Milestone extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'name',
        'description',
        'start_date',
        'due_date',
        'completed_date',
        'status',
        'progress',
    ];

    protected static function newFactory(): MilestoneFactory
    {
        return MilestoneFactory::new();
    }

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'start_date',
        'due_date',
        'completed_date',
        'status',
        'progress',
        'order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_date' => 'date',
        'progress' => 'decimal:2',
    ];

    // المشروع
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // المهام المرتبطة بهذه المرحلة
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // مخرجات المرحلة
    public function deliverables(): HasMany
    {
        return $this->hasMany(MilestoneDeliverable::class)->orderBy('order');
    }

    // التحقق من التأخر
    public function isOverdue(): bool
    {
        return $this->status !== 'completed'
            && $this->due_date
            && $this->due_date->isPast();
    }
}
