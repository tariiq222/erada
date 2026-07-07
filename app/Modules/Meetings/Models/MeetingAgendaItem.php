<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Database\Factories\MeetingAgendaItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeetingAgendaItem extends Model
{
    use HasFactory, HasOrganizationScope, SoftDeletes;

    protected static function newFactory(): MeetingAgendaItemFactory
    {
        return MeetingAgendaItemFactory::new();
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'قيد المراجعة',
        self::STATUS_APPROVED => 'معتمد',
        self::STATUS_REJECTED => 'مرفوض',
    ];

    protected $fillable = [
        'meeting_id', 'title', 'description', 'proposed_by_id',
        'status', 'position', 'review_note', 'organization_id',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected $appends = ['status_label'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /** @return array<int, string> */
    public static function statusValues(): array
    {
        return array_keys(self::STATUSES);
    }
}
