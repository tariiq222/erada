<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use Database\Factories\SurveyResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class SurveyResponse extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return SurveyResponseFactory::new();
    }

    /**
     * Phase 3A — Phase 3A — snapshot the respondent_organization_id
     * at create time, decoupled from the live users.organization_id
     * relation. The cluster aggregate groups by the snapshot; once
     * stamped the historical attribution survives user org moves
     * and deletions.
     *
     * Order of resolution:
     *   1. Caller-supplied respondent_organization_id (used by the
     *      forward-only backfill migration — never by submission paths).
     *   2. Respondent's current organization (users.organization_id
     *      when respondent_id is set and the user still exists).
     *   3. Survey's organization (fallback for anonymous / deleted
     *      respondents — same contract the migration backfills with).
     *   4. NULL (no org can be determined; the row is an edge case
     *      the cluster aggregate ignores).
     */
    protected static function booted(): void
    {
        static::creating(function (SurveyResponse $response): void {
            if ($response->respondent_organization_id !== null) {
                return;
            }

            // Path 2 — live respondent org (the typical authenticated
            // submission path).
            if ($response->respondent_id !== null) {
                $respondent = User::query()->find($response->respondent_id);
                if ($respondent !== null && $respondent->organization_id !== null) {
                    $response->respondent_organization_id = (int) $respondent->organization_id;

                    return;
                }
            }

            // Path 3 — survey org fallback (anonymous / deleted
            // respondents).
            if ($response->survey_id !== null) {
                $surveyOrg = \DB::table('surveys')
                    ->where('id', $response->survey_id)
                    ->value('organization_id');
                if ($surveyOrg !== null) {
                    $response->respondent_organization_id = (int) $surveyOrg;

                    return;
                }
            }

            // Path 4 — no org available. Log at warning so a
            // stray NULL surfaces during review; the cluster
            // aggregate simply skips the row.
            Log::warning('SurveyResponse created without a stamped organization', [
                'survey_id' => $response->survey_id,
                'respondent_id' => $response->respondent_id,
            ]);
        });
    }

    protected $fillable = [
        'survey_id',
        'survey_version_id',
        'respondent_type',
        'respondent_id',
        'respondent_organization_id',
        'respondent_name',
        'respondent_email',
        'respondent_phone',
        'invitation_id',
        'status',
        'ip_hash',
        'fingerprint_hash',
        'user_agent',
        'completion_time',
        'consented_at',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'reviewer_notes',
        'metadata',
        'answers_snapshot',
    ];

    protected $casts = [
        'status' => ResponseStatus::class,
        'consented_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
        'answers_snapshot' => 'array',
        // PII — application-level encryption (Laravel encrypted cast)
        // 'respondent_phone' => 'encrypted'  -- NOT ENCRYPTED: column is varchar(30); encrypted payload exceeds limit. Requires migration to widen column.
        'respondent_name' => 'encrypted',
    ];

    // TODO: hash-based lookup
    // respondent_email is intentionally NOT encrypted because the
    // duplicate-response detector in App\Modules\Surveys\Services\ResponseService::checkDuplicateResponse()
    // performs an exact-match WHERE query (line ~275). To encrypt it, we
    // would need to (a) add a deterministic respondent_email_hmac column via
    // migration, and (b) update that service to query the HMAC. Both are
    // out of scope per the current task constraints.

    // ========================================
    // العلاقات
    // ========================================

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyFieldAnswer::class, 'response_id');
    }

    public function importRequests(): HasMany
    {
        return $this->hasMany(DataImportRequest::class, 'response_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeSubmitted($query)
    {
        return $query->where('status', ResponseStatus::Submitted);
    }

    public function scopeFlagged($query)
    {
        return $query->where('status', ResponseStatus::Flagged);
    }

    public function scopeFromPublic($query)
    {
        return $query->where('respondent_type', 'public');
    }

    public function scopeFromUsers($query)
    {
        return $query->where('respondent_type', 'user');
    }

    // ========================================
    // Helpers
    // ========================================

    public function isFromAuthenticatedUser(): bool
    {
        return $this->respondent_type === 'user' && $this->respondent_id !== null;
    }

    public function flag(?string $reason = null): void
    {
        $this->status = ResponseStatus::Flagged;
        if ($reason) {
            $this->reviewer_notes = $reason;
        }
        $this->save();
    }

    public function markAsReviewed(User $reviewer, ?string $notes = null): void
    {
        $this->reviewed_at = now();
        $this->reviewed_by = $reviewer->id;
        if ($notes) {
            $this->reviewer_notes = $notes;
        }
        $this->save();
    }

    /**
     * الحصول على إجابة حقل معين
     */
    public function getAnswer(string $fieldKey): ?SurveyFieldAnswer
    {
        return $this->answers()->where('field_key', $fieldKey)->first();
    }

    /**
     * الحصول على قيمة إجابة حقل معين
     */
    public function getAnswerValue(string $fieldKey): mixed
    {
        $answer = $this->getAnswer($fieldKey);

        return $answer?->answer_value;
    }

    /**
     * الحصول على كل الإجابات كـ key => value
     */
    public function getAnswersAsArray(): array
    {
        return $this->answers->pluck('answer_value', 'field_key')->toArray();
    }

    /**
     * الحصول على اسم المستجيب
     */
    public function getRespondentDisplayName(): string
    {
        if ($this->respondent) {
            return $this->respondent->name;
        }

        return $this->respondent_name ?? $this->respondent_email ?? 'مجهول';
    }
}
