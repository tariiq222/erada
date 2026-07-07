<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SurveyInvitation extends Model
{
    protected $fillable = [
        'survey_id',
        'email',
        'name',
        'department_id',
        'user_id',
        'status',
        'expires_at',
        'max_uses',
        'used_count',
        'revoked_at',
        'opened_at',
        'completed_at',
        'response_id',
        'sent_at',
        'reminded_at',
        'reminder_count',
        'created_by',
    ];

    protected $hidden = [
        'token',
    ];

    protected $attributes = [
        'max_uses' => 1,
        'used_count' => 0,
        'status' => 'active',
    ];

    protected $casts = [
        'status' => InvitationStatus::class,
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'opened_at' => 'datetime',
        'completed_at' => 'datetime',
        'sent_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SurveyInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = static::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeActive($query)
    {
        return $query->where('status', InvitationStatus::Active);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', InvitationStatus::Expired)
            ->orWhere(fn ($q) => $q
                ->where('status', InvitationStatus::Active)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()));
    }

    // ========================================
    // Helpers
    // ========================================

    public function canUse(): bool
    {
        if ($this->status !== InvitationStatus::Active) {
            return false;
        }
        if ($this->expires_at && $this->expires_at < now()) {
            return false;
        }
        // Null max_uses = single-use (legacy data). Anything > 0 must allow up to that count.
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }
        if (! $this->survey?->isActive()) {
            return false;
        }

        return true;
    }

    public function markAsOpened(): void
    {
        if (! $this->opened_at) {
            $this->opened_at = now();
            $this->save();
        }
    }

    public function markAsUsed(SurveyResponse $response): void
    {
        $this->used_count++;
        $this->completed_at = now();
        $this->response_id = $response->id;

        // Treat any non-null max_uses <= used_count as exhausted, AND always flip to
        // Used on the first submission for single-use invites (max_uses == 1).
        if ($this->max_uses === null || $this->used_count >= $this->max_uses) {
            $this->status = InvitationStatus::Used;
        }

        $this->save();
    }

    public function revoke(): void
    {
        $this->status = InvitationStatus::Revoked;
        $this->revoked_at = now();
        $this->save();
    }

    public function getUrl(): string
    {
        return url("/surveys/invitation/{$this->token}");
    }

    public function updateExpiredStatus(): void
    {
        if ($this->status === InvitationStatus::Active
            && $this->expires_at
            && $this->expires_at < now()) {
            $this->status = InvitationStatus::Expired;
            $this->save();
        }
    }
}
