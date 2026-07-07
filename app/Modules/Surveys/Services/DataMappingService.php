<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\DataTransform;
use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Enums\InsertPolicy;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Notifications\DataImportFailedNotification;
use App\Modules\Surveys\Notifications\DataImportPendingNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataMappingService
{
    /**
     * إنشاء طلبات الاستيراد من response
     */
    public function createImportRequestsFromResponse(SurveyResponse $response): array
    {
        $survey = $response->survey;
        $template = $survey->activeMappingTemplate;

        if (! $template) {
            return [];
        }

        $answers = $response->getAnswersAsArray();

        // التحقق من اكتمال البيانات المطلوبة
        $errors = $template->validateAnswers($answers);
        if (! empty($errors)) {
            return []; // لا ننشئ طلب إذا البيانات ناقصة
        }

        // تحويل البيانات
        $payload = $this->transformAnswersToPayload($template, $answers, $survey->organization_id);

        // تحديد العملية
        $operation = $this->determineOperation($template, $payload);

        // البحث عن سجل موجود إذا كان upsert أو update
        $existingRecord = null;
        $diff = null;

        if ($operation !== 'create') {
            $existingRecord = $this->findExistingRecord($template, $payload, $survey->organization_id);

            if ($existingRecord) {
                $operation = 'update';
                $diff = $this->calculateDiff($existingRecord, $payload);
            } elseif ($template->insert_policy === InsertPolicy::UpdateOnly) {
                return []; // لا ننشئ طلب إذا السجل غير موجود
            } else {
                $operation = 'create';
            }
        }

        // تحديد الحالة
        $status = $this->determineInitialStatus($template, $existingRecord, $diff);

        // إنشاء طلب الاستيراد
        $request = DataImportRequest::create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'target_table' => $template->target_model,
            'target_id' => $existingRecord?->id,
            'operation' => $operation,
            'payload' => $payload,
            'diff' => $diff,
            'upsert_key_field' => array_key_first($template->getUpsertKeyFields()),
            'upsert_key_value' => $payload[array_values($template->getUpsertKeyFields())[0] ?? ''] ?? null,
            'status' => $status,
            'priority' => 0,
            'requested_at' => now(),
        ]);

        // إرسال إشعار إذا كان Pending
        if ($status === ImportStatus::Pending) {
            DB::afterCommit(fn () => $this->notifyAdminsAboutPendingImport($request));
        }

        return [$request];
    }

    /**
     * إرسال إشعار للمديرين عن طلب استيراد معلّق
     */
    protected function notifyAdminsAboutPendingImport(DataImportRequest $request): void
    {
        $survey = $request->response?->survey;
        if (! $survey) {
            return;
        }

        // إرسال للمنشئ
        if ($survey->created_by) {
            $survey->creator?->notify(new DataImportPendingNotification($request));
        }

        // إرسال لكل المشرفين في نفس المؤسسة
        $admins = User::where('organization_id', $survey->organization_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
            ->get();

        foreach ($admins as $admin) {
            if ($admin->id !== $survey->created_by) {
                $admin->notify(new DataImportPendingNotification($request));
            }
        }
    }

    /**
     * تحويل الإجابات إلى payload
     */
    public function transformAnswersToPayload(DataMappingTemplate $template, array $answers, ?int $organizationId = null): array
    {
        $payload = [];

        foreach ($template->mappings as $fieldKey => $mapping) {
            $column = $mapping['column'] ?? null;
            $transforms = $mapping['transforms'] ?? [];
            $value = $answers[$fieldKey] ?? null;

            if (! $column || ! DataMappingTemplate::isAllowedColumn($template->target_model, $column)) {
                continue;
            }

            // تطبيق التحويلات
            $value = DataTransform::applyTransforms($value, $transforms, $organizationId);

            $payload[$column] = $value;
        }

        return $template->sanitizePayload($payload);
    }

    /**
     * تحديد نوع العملية
     */
    protected function determineOperation(DataMappingTemplate $template, array $payload): string
    {
        return match ($template->insert_policy) {
            InsertPolicy::CreateOnly => 'create',
            InsertPolicy::UpdateOnly => 'update',
            InsertPolicy::Upsert => 'upsert',
        };
    }

    /**
     * البحث عن سجل موجود
     */
    protected function findExistingRecord(DataMappingTemplate $template, array $payload, ?int $organizationId = null): ?object
    {
        $modelClass = $template->getModelClass();
        if (! $modelClass) {
            return null;
        }

        $upsertKeys = $template->getUpsertKeyFields();
        if (empty($upsertKeys)) {
            return null;
        }

        $query = $modelClass::query();

        foreach ($upsertKeys as $fieldKey => $column) {
            if (isset($payload[$column])) {
                $query->where($column, $payload[$column]);
            }
        }

        if ($organizationId && $this->modelHasColumn($modelClass, 'organization_id')) {
            $query->where('organization_id', $organizationId);
        }

        return $query->first();
    }

    /**
     * حساب الفرق بين السجل الموجود والبيانات الجديدة
     */
    protected function calculateDiff(object $existing, array $payload): array
    {
        $diff = [];

        foreach ($payload as $column => $newValue) {
            $oldValue = $existing->{$column} ?? null;

            if ($oldValue !== $newValue) {
                $diff[$column] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }

    /**
     * تحديد الحالة الأولية
     */
    protected function determineInitialStatus(
        DataMappingTemplate $template,
        ?object $existingRecord,
        ?array $diff
    ): ImportStatus {
        // إذا كان تحديث مع سياسة overwrite بدون تعارض
        if ($existingRecord && empty($diff)) {
            return ImportStatus::Applied; // لا شيء للتغيير
        }

        // حسب سياسة التعارض
        return match ($template->conflict_policy) {
            ConflictPolicy::Skip => $existingRecord ? ImportStatus::Applied : ImportStatus::Pending,
            ConflictPolicy::Overwrite => ImportStatus::Approved, // جاهز للتطبيق
            ConflictPolicy::RequireReview => ImportStatus::Pending,
        };
    }

    /**
     * تطبيق طلب استيراد معتمد
     */
    public function applyImportRequest(DataImportRequest $request): bool
    {
        if (! $request->canApply()) {
            return false;
        }

        try {
            DB::beginTransaction();

            $template = $request->template;
            $modelClass = $template?->getModelClass();

            if (! $modelClass) {
                throw new \Exception("نموذج غير معروف: {$request->target_table}");
            }

            $organizationId = $request->response?->survey?->organization_id;
            $payload = $this->sanitizePayloadForTemplate($template, $request->payload ?? []);

            if ($request->operation === 'create') {
                if ($organizationId && $this->modelHasColumn($modelClass, 'organization_id')) {
                    $payload['organization_id'] = $organizationId;
                }

                $record = $modelClass::create($payload);
                $request->markAsApplied($record->id);
            } else {
                $record = $modelClass::findOrFail($request->target_id);

                if (! $this->targetBelongsToOrganization($record, $organizationId)) {
                    throw new \Exception('لا يمكن تطبيق الطلب على سجل خارج مؤسسة الاستبيان');
                }

                $record->update($payload);
                $request->markAsApplied($record->id);
            }

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $request->markAsFailed($e->getMessage());

            // إرسال إشعار بالفشل
            $reviewer = User::find($request->reviewed_by);
            if ($reviewer) {
                $reviewer->notify(new DataImportFailedNotification($request));
            }

            // إرسال للمنشئ أيضاً
            $survey = $request->response?->survey;
            if ($survey?->created_by) {
                $survey->creator?->notify(new DataImportFailedNotification($request));
            }

            return false;
        }
    }

    /**
     * تطبيق عدة طلبات
     */
    public function bulkApply(array $requestIds): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        $requests = DataImportRequest::whereIn('id', $requestIds)
            ->where('status', ImportStatus::Approved)
            ->get();

        foreach ($requests as $request) {
            if ($this->applyImportRequest($request)) {
                $results['success'][] = $request->id;
            } else {
                $results['failed'][] = $request->id;
            }
        }

        return $results;
    }

    public function sanitizePayloadForTemplate(DataMappingTemplate $template, array $payload): array
    {
        return $template->sanitizePayload($payload);
    }

    protected function targetBelongsToOrganization(object $record, ?int $organizationId): bool
    {
        if (! $organizationId || ! array_key_exists('organization_id', $record->getAttributes())) {
            return true;
        }

        return (int) $record->organization_id === $organizationId;
    }

    protected function modelHasColumn(string $modelClass, string $column): bool
    {
        $model = new $modelClass;

        return Schema::hasColumn($model->getTable(), $column);
    }
}
