<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Database\Factories\MeetingCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeetingCategory extends Model
{
    use HasFactory, HasOrganizationScope, SoftDeletes;

    protected static function newFactory(): MeetingCategoryFactory
    {
        return MeetingCategoryFactory::new();
    }

    protected $fillable = [
        'name', 'is_active', 'sort_order', 'organization_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'category_id');
    }
}
