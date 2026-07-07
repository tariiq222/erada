<?php

namespace App\Modules\Shared\Models;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'content',
    ];

    protected $fillable = [
        'user_id',
        'content',
        'commentable_type',
        'commentable_id',
    ];

    // المستخدم
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // العنصر المرتبط (مشروع، مهمة، إلخ)
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    // المرفقات
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
