<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentCapacityRole extends Model
{
    public const CAPACITY_MEMBER = 'member';

    public const CAPACITY_MANAGER = 'manager';

    protected $fillable = [
        'department_id',
        'capacity',
        'role_key',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
