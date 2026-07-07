<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Surveys\Enums\SurveyPrivacyMode;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Model implements ScopeAware
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): SurveyFactory
    {
        return SurveyFactory::new();
    }

    protected $fillable = [
        'code',
        'organization_id',
        'department_id',
        'canonical_id',
        'revision',
        'title',
        'description',
        'type',
        'category',
        'status',
        'is_public',
        'requires_auth',
        'privacy_mode',
        'accepting_responses',
        'allow_multiple_responses',
        'allow_edit_response',
        'starts_at',
        'ends_at',
        'published_at',
        'locked_at',
        'closed_at',
        'close_reason',
        'consent_text',
        'consent_required',
        'welcome_message',
        'thank_you_message',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'type' => SurveyType::class,
        'status' => SurveyStatus::class,
        'privacy_mode' => SurveyPrivacyMode::class,
        'is_public' => 'boolean',
        'requires_auth' => 'boolean',
        'accepting_responses' => 'boolean',
        'allow_multiple_responses' => 'boolean',
        'allow_edit_response' => 'boolean',
        'consent_required' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'locked_at' => 'datetime',
        'closed_at' => 'datetime',
        'settings' => 'array',
    ];

    protected array $trackedFields = [
        'title',
        'status',
        'type',
        'is_public',
        'requires_auth',
        'privacy_mode',
    ];

    protected static function booted(): void
    {
        static::creating(function (Survey $survey) {
            if (empty($survey->code)) {
                $survey->code = static::generateCode($survey->organization_id);
            }
            // تعيين revision افتراضي للاستبيانات الجديدة
            if ($survey->revision === null) {
                $survey->revision = 1;
            }
            if ($survey->privacy_mode === null) {
                $survey->privacy_mode = SurveyPrivacyMode::Identified;
            }
        });
    }

    public static function generateCode(?int $organizationId): string
    {
        $year = date('Y');
        $prefix = "SRV-{$year}-";

        $query = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->whereNull('canonical_id'); // فقط الأصلية

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        // استخدام RIGHT() بدلاً من SUBSTRING للتوافق الأفضل
        $last = $query->orderByRaw('CAST(RIGHT(code, 3) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -3)) + 1 : 1;

        return $prefix.str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    // ========================================
    // العلاقات
    // ========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function canonical(): BelongsTo
    {
        return $this->belongsTo(Survey::class, 'canonical_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Survey::class, 'canonical_id')->orderBy('revision');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SurveySection::class)->orderBy('order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class)->orderBy('order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(SurveyVersion::class)->latestOfMany();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function mappingTemplates(): HasMany
    {
        return $this->hasMany(DataMappingTemplate::class);
    }

    public function activeMappingTemplate(): HasOne
    {
        return $this->hasOne(DataMappingTemplate::class)->where('is_active', true);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopePublished($query)
    {
        return $query->where('status', SurveyStatus::Published);
    }

    public function scopeActive($query)
    {
        return $query->published()
            ->where('accepting_responses', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeCanonical($query)
    {
        return $query->whereNull('canonical_id');
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        if ($organizationId === null) {
            return $query->whereRaw('false');
        }

        return $query->where('organization_id', $organizationId);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isActive(): bool
    {
        return $this->status === SurveyStatus::Published
            && $this->accepting_responses
            && (! $this->starts_at || $this->starts_at <= now())
            && (! $this->ends_at || $this->ends_at >= now());
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function canEdit(): bool
    {
        return ! $this->isLocked() && $this->status->canEdit();
    }

    public function canPublish(): bool
    {
        return $this->status->canPublish() && $this->fields()->count() > 0;
    }

    public function canClose(): bool
    {
        return $this->status->canClose();
    }

    public function privacyMode(): SurveyPrivacyMode
    {
        return $this->privacy_mode instanceof SurveyPrivacyMode
            ? $this->privacy_mode
            : SurveyPrivacyMode::Identified;
    }

    public function isAnonymous(): bool
    {
        return $this->privacyMode() === SurveyPrivacyMode::Anonymous;
    }

    public function isConfidential(): bool
    {
        return $this->privacyMode() === SurveyPrivacyMode::Confidential;
    }

    public function getPublicUrl(): string
    {
        return url("/s/{$this->code}");
    }

    public function getPublicUrlWithRevision(): string
    {
        return url("/s/{$this->code}?rev={$this->revision}");
    }

    /**
     * الحصول على آخر نسخة منشورة من سلسلة الاستبيانات
     */
    public function getLatestPublishedRevision(): ?Survey
    {
        $canonicalId = $this->canonical_id ?? $this->id;

        return static::where(function ($query) use ($canonicalId) {
            $query->where('id', $canonicalId)
                ->orWhere('canonical_id', $canonicalId);
        })
            ->where('status', SurveyStatus::Published)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * إنشاء نسخة جديدة من الاستبيان
     */
    public function createNewRevision(): Survey
    {
        $canonicalId = $this->canonical_id ?? $this->id;

        $maxRevision = static::where(function ($query) use ($canonicalId) {
            $query->where('id', $canonicalId)
                ->orWhere('canonical_id', $canonicalId);
        })->max('revision');

        $newSurvey = $this->replicate([
            'status',
            'published_at',
            'locked_at',
            'closed_at',
            'close_reason',
        ]);

        $newSurvey->canonical_id = $canonicalId;
        $newSurvey->revision = $maxRevision + 1;
        $newSurvey->status = SurveyStatus::Draft;
        $newSurvey->save();

        // نسخ الأقسام والحقول
        foreach ($this->sections as $section) {
            $newSection = $section->replicate();
            $newSection->survey_id = $newSurvey->id;
            $newSection->save();

            foreach ($section->fields as $field) {
                $newField = $field->replicate();
                $newField->survey_id = $newSurvey->id;
                $newField->section_id = $newSection->id;
                $newField->save();
            }
        }

        // نسخ الحقول بدون قسم
        foreach ($this->fields()->whereNull('section_id')->get() as $field) {
            $newField = $field->replicate();
            $newField->survey_id = $newSurvey->id;
            $newField->save();
        }

        return $newSurvey;
    }

    /**
     * الحصول على إعداد معين
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * تحديث إعداد معين
     */
    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
    }

    /**
     * هل الاستبيان يُنشئ طلبات استيراد
     */
    public function createsImportRequests(): bool
    {
        // Initial دائماً ينشئ
        if ($this->type === SurveyType::Initial) {
            return true;
        }

        // Periodic فقط إذا مفعّل
        return $this->getSetting('enable_import', false);
    }

    // ========================================
    // ScopeAware
    // ========================================

    public function scopeParent(): ?Model
    {
        return AccessDecision::resolveScopeParent(Department::class, $this->department_id ?: null);
    }

    public function scopeTypeKey(): string
    {
        return 'survey';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }
}
