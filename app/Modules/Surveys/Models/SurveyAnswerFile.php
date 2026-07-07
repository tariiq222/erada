<?php

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SurveyAnswerFile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'answer_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function answer(): BelongsTo
    {
        return $this->belongsTo(SurveyFieldAnswer::class, 'answer_id');
    }

    // ========================================
    // Helpers
    // ========================================

    public function getUrl(): string
    {
        return Storage::url($this->file_path);
    }

    public function getTemporaryUrl(int $minutes = 60): string
    {
        return Storage::temporaryUrl($this->file_path, now()->addMinutes($minutes));
    }

    public function getSizeFormatted(): string
    {
        $bytes = $this->size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function delete(): bool
    {
        // حذف الملف الفعلي
        Storage::delete($this->file_path);

        return parent::delete();
    }
}
