<?php

namespace App\Modules\Projects\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stakeholder extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'name',
        'email',
        'phone',
        'organization',
        'role',
        'influence',
        'interest',
        'notes',
    ];

    // المستخدم المرتبط (إن وجد)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // المشروع
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
