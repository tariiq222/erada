<?php

namespace App\Modules\Shared\Models;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'file_type',
        'file_size',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'file_path',
        'file_type',
        'file_size',
        'attachable_type',
        'attachable_id',
    ];

    protected $appends = ['formatted_size'];

    // المستخدم
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // العنصر المرتبط
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    // حجم الملف بصيغة مقروءة
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }
}
