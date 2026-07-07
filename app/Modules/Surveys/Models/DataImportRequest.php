<?php

namespace App\Modules\Surveys\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Surveys\Enums\ImportStatus;
use Database\Factories\DataImportRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImportRequest extends Model implements ScopeAware
{
    use HasFactory, LogsActivity;

    protected static function newFactory()
    {
        return DataImportRequestFactory::new();
    }

    protected $fillable = [
        'response_id',
        'template_id',
        'target_table',
        'target_id',
        'operation',
        'payload',
        'diff',
        'upsert_key_field',
        'upsert_key_value',
        'status',
        'priority',
        'requested_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'applied_at',
        'applied_id',
        'error_message',
    ];

    protected $casts = [
        'status' => ImportStatus::class,
        'payload' => 'array',
        'diff' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    protected array $trackedFields = [
        'status',
    ];

    // ========================================
    // العلاقات
    // ========================================

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'response_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DataMappingTemplate::class, 'template_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopePending($query)
    {
        return $query->where('status', ImportStatus::Pending);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ImportStatus::Approved);
    }

    public function scopeReadyToApply($query)
    {
        return $query->approved();
    }

    // ========================================
    // Actions
    // ========================================

    public function approve(User|int $reviewer, ?string $notes = null): bool
    {
        if (! $this->status->canApprove()) {
            return false;
        }

        $this->status = ImportStatus::Approved;
        $this->reviewed_at = now();
        $this->reviewed_by = $reviewer instanceof User ? $reviewer->id : $reviewer;

        if ($notes) {
            $this->rejection_reason = null; // مسح أي سبب رفض سابق
        }

        return $this->save();
    }

    public function reject(User|int $reviewer, string $reason): bool
    {
        if (! $this->status->canReject()) {
            return false;
        }

        $this->status = ImportStatus::Rejected;
        $this->reviewed_at = now();
        $this->reviewed_by = $reviewer instanceof User ? $reviewer->id : $reviewer;
        $this->rejection_reason = $reason;

        return $this->save();
    }

    public function markAsApplied(int $recordId): void
    {
        $this->status = ImportStatus::Applied;
        $this->applied_at = now();
        $this->applied_id = $recordId;
        $this->save();
    }

    public function markAsFailed(string $error): void
    {
        $this->status = ImportStatus::Failed;
        $this->error_message = $error;
        $this->save();
    }

    public function resetForRetry(): bool
    {
        if (! $this->status->canRetry()) {
            return false;
        }

        $this->status = ImportStatus::Approved;
        $this->error_message = null;
        $this->applied_at = null;
        $this->applied_id = null;

        return $this->save();
    }

    // ========================================
    // Helpers
    // ========================================

    public function canApprove(): bool
    {
        return $this->status->canApprove();
    }

    public function canReject(): bool
    {
        return $this->status->canReject();
    }

    public function canApply(): bool
    {
        return $this->status->canApply();
    }

    /**
     * الحصول على اسم الجدول المستهدف بالعربي
     */
    public function getTargetTableLabel(): string
    {
        $models = DataMappingTemplate::getAvailableTargetModels();

        return $models[$this->target_table]['label'] ?? $this->target_table;
    }

    /**
     * الحصول على ملخص العملية
     */
    public function getOperationSummary(): string
    {
        $operation = match ($this->operation) {
            'create' => 'إنشاء',
            'update' => 'تحديث',
            'upsert' => 'إنشاء أو تحديث',
            default => $this->operation,
        };

        return "{$operation} في {$this->getTargetTableLabel()}";
    }

    /**
     * هل يوجد تعارض (للتحديث)
     */
    public function hasConflict(): bool
    {
        return ! empty($this->diff);
    }

    /**
     * الحصول على حقول الـ diff
     */
    public function getDiffFields(): array
    {
        if (! $this->diff) {
            return [];
        }

        $fields = [];
        foreach ($this->diff as $field => $values) {
            $fields[$field] = [
                'old' => $values['old'] ?? null,
                'new' => $values['new'] ?? null,
            ];
        }

        return $fields;
    }

    // ========================================
    // ScopeAware
    // ========================================
    // An import request rolls up through its SurveyResponse to the Survey.
    // SurveyResponse is itself a non-scoped child of the Survey, so the nearest
    // ScopeAware ancestor is the Survey: scopeParent() returns the Survey directly
    // (skipping the response), and the engine ascends from there to the department
    // and organization. This model has no own organization_id column.

    public function scopeParent(): ?Model
    {
        // Resolve the parent Survey via the response. Both lookups go through the
        // engine identity map (cached by id) so a list of N requests sharing one
        // survey triggers one response fetch and one survey fetch, not N (N+1 fix).
        $surveyId = $this->resolveSurveyId();

        return $surveyId !== null
            ? AccessDecision::resolveScopeParent(Survey::class, $surveyId)
            : null;
    }

    public function scopeTypeKey(): string
    {
        // Adopt the parent module's key; import requests have no separate scope_type.
        return 'survey';
    }

    public function scopeOrganizationId(): ?int
    {
        // No own organization_id column: derive it from the parent Survey.
        $survey = $this->scopeParent();

        return $survey instanceof ScopeAware ? $survey->scopeOrganizationId() : null;
    }

    /**
     * Resolve the owning survey id by hopping through the SurveyResponse, routed
     * through the engine cache so the response is fetched at most once per request.
     */
    private function resolveSurveyId(): ?int
    {
        if ($this->response_id === null) {
            return null;
        }

        $response = AccessDecision::resolveScopeParent(SurveyResponse::class, (int) $this->response_id);

        return $response?->survey_id !== null ? (int) $response->survey_id : null;
    }
}
