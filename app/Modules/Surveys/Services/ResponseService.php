<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Notifications\NewSurveyResponseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResponseService
{
    public function __construct(
        protected VersioningService $versioningService,
        protected DataMappingService $mappingService
    ) {}

    /**
     * إنشاء response من الطلب العام
     */
    public function createPublicResponse(
        Survey $survey,
        array $answers,
        Request $request,
        ?SurveyInvitation $invitation = null
    ): SurveyResponse {
        // التحقق من أن الاستبيان نشط
        if (! $survey->isActive()) {
            throw ValidationException::withMessages([
                'survey' => 'الاستبيان غير متاح حالياً',
            ]);
        }

        // التحقق من التكرار
        $this->checkDuplicateSubmission($survey, $request, $invitation);

        return DB::transaction(function () use ($survey, $answers, $request, $invitation) {
            // الحصول على النسخة
            $version = $this->versioningService->getOrCreateVersion($survey);

            // معالجة الإجابات (تطبيق conditional logic)
            $processedAnswers = $this->processAnswers($survey, $answers);

            // إنشاء الـ response
            $respondentName = $survey->isAnonymous() ? null : $request->input('respondent_name');
            $respondentEmail = $survey->isAnonymous() ? null : $request->input('respondent_email');
            $respondentPhone = $survey->isAnonymous() ? null : $request->input('respondent_phone');

            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'survey_version_id' => $version->id,
                'respondent_type' => 'public',
                'respondent_name' => $respondentName,
                'respondent_email' => $respondentEmail,
                'respondent_phone' => $respondentPhone,
                'invitation_id' => $invitation?->id,
                'status' => ResponseStatus::Submitted,
                'ip_hash' => hash('sha256', $request->ip()),
                'fingerprint_hash' => $request->header('X-Fingerprint-Hash'),
                'user_agent' => $request->userAgent(),
                'completion_time' => $request->input('completion_time'),
                'consented_at' => $survey->consent_required ? now() : null,
                'submitted_at' => now(),
            ]);

            // حفظ الإجابات
            $this->saveAnswers($response, $survey, $processedAnswers);

            // تحديث الدعوة
            if ($invitation) {
                $invitation->markAsUsed($response);
            }

            // إنشاء طلبات الاستيراد إذا لزم
            if ($survey->createsImportRequests()) {
                $this->mappingService->createImportRequestsFromResponse($response);
            }

            // إرسال إشعار للمنشئ
            if ($survey->created_by) {
                DB::afterCommit(fn () => $survey->creator?->notify(new NewSurveyResponseNotification($survey, $response)));
            }

            return $response;
        });
    }

    /**
     * إنشاء response من مستخدم مصادق
     */
    public function createAuthenticatedResponse(
        Survey $survey,
        array $answers,
        User $user,
        Request $request
    ): SurveyResponse {
        if (! $survey->isActive()) {
            throw ValidationException::withMessages([
                'survey' => 'الاستبيان غير متاح حالياً',
            ]);
        }

        // التحقق من الصلاحية
        if (! $this->canUserRespond($survey, $user)) {
            throw ValidationException::withMessages([
                'user' => 'ليس لديك صلاحية الإجابة على هذا الاستبيان',
            ]);
        }

        // التحقق من التكرار
        if (! $survey->allow_multiple_responses) {
            $existing = $survey->responses()
                ->where('respondent_id', $user->id)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'duplicate' => 'لقد قمت بالإجابة على هذا الاستبيان مسبقاً',
                ]);
            }
        }

        return DB::transaction(function () use ($survey, $answers, $user, $request) {
            $version = $this->versioningService->getOrCreateVersion($survey);
            $processedAnswers = $this->processAnswers($survey, $answers);

            $respondentId = $survey->isAnonymous() ? null : $user->id;
            $respondentName = $survey->isAnonymous() ? null : $user->name;
            $respondentEmail = $survey->isAnonymous() ? null : $user->email;

            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'survey_version_id' => $version->id,
                'respondent_type' => 'user',
                'respondent_id' => $respondentId,
                'respondent_name' => $respondentName,
                'respondent_email' => $respondentEmail,
                'status' => ResponseStatus::Submitted,
                'ip_hash' => hash('sha256', $request->ip()),
                'fingerprint_hash' => $request->header('X-Fingerprint-Hash'),
                'user_agent' => $request->userAgent(),
                'completion_time' => $request->input('completion_time'),
                'consented_at' => $survey->consent_required ? now() : null,
                'submitted_at' => now(),
            ]);

            $this->saveAnswers($response, $survey, $processedAnswers);

            if ($survey->createsImportRequests()) {
                $this->mappingService->createImportRequestsFromResponse($response);
            }

            // إرسال إشعار للمنشئ
            if ($survey->created_by) {
                DB::afterCommit(fn () => $survey->creator?->notify(new NewSurveyResponseNotification($survey, $response)));
            }

            return $response;
        });
    }

    /**
     * معالجة الإجابات مع تطبيق conditional logic
     */
    protected function processAnswers(Survey $survey, array $submittedAnswers): array
    {
        $fields = $survey->fields->keyBy('field_key');
        $processedAnswers = [];

        foreach ($submittedAnswers as $fieldKey => $value) {
            $field = $fields->get($fieldKey);

            if (! $field) {
                continue; // تجاهل الحقول غير الموجودة
            }

            // تطبيق conditional logic
            if (! $field->isVisibleForAnswers($submittedAnswers)) {
                // التحقق من security_sensitive
                if ($field->isSecuritySensitive()) {
                    throw ValidationException::withMessages([
                        $fieldKey => 'حقل محمي أُرسل بشكل غير صحيح',
                    ]);
                }

                // تجاهل الحقول المخفية العادية
                continue;
            }

            // التحقق من الحقول المطلوبة
            if ($field->is_required && ($value === null || $value === '' || $value === [])) {
                throw ValidationException::withMessages([
                    $fieldKey => "الحقل {$field->label} مطلوب",
                ]);
            }

            $processedAnswers[$fieldKey] = $value;
        }

        // التحقق من الحقول المطلوبة المفقودة
        foreach ($fields as $field) {
            if ($field->is_required
                && $field->isVisibleForAnswers($submittedAnswers)
                && ! isset($processedAnswers[$field->field_key])) {
                throw ValidationException::withMessages([
                    $field->field_key => "الحقل {$field->label} مطلوب",
                ]);
            }
        }

        return $processedAnswers;
    }

    /**
     * حفظ الإجابات
     */
    protected function saveAnswers(SurveyResponse $response, Survey $survey, array $answers): void
    {
        $fields = $survey->fields->keyBy('field_key');

        foreach ($answers as $fieldKey => $value) {
            $field = $fields->get($fieldKey);

            if ($field && $field->type->storesValue()) {
                SurveyFieldAnswer::createFromValue($response, $field, $value);
            }
        }
    }

    /**
     * التحقق من التكرار
     */
    protected function checkDuplicateSubmission(
        Survey $survey,
        Request $request,
        ?SurveyInvitation $invitation
    ): void {
        if ($survey->allow_multiple_responses) {
            return;
        }

        $duplicateSettings = $survey->getSetting('duplicate_protection', []);

        if (! ($duplicateSettings['enabled'] ?? false)) {
            return;
        }

        $key = $duplicateSettings['key'] ?? 'fingerprint';
        $windowMinutes = $duplicateSettings['window_minutes'] ?? 1440;

        $query = $survey->responses()
            ->where('created_at', '>=', now()->subMinutes($windowMinutes));

        switch ($key) {
            case 'fingerprint':
                $fingerprint = $request->header('X-Fingerprint-Hash');
                if ($fingerprint) {
                    $query->where('fingerprint_hash', $fingerprint);
                }
                break;

            case 'ip':
                $ipHash = hash('sha256', $request->ip());
                $query->where('ip_hash', $ipHash);
                break;

            case 'email':
                $email = $request->input('respondent_email');
                if ($email) {
                    $query->where('respondent_email', $email);
                }
                break;

            case 'invitation':
                if ($invitation) {
                    $query->where('invitation_id', $invitation->id);
                }
                break;
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'duplicate' => 'لقد قمت بالإجابة على هذا الاستبيان مسبقاً',
            ]);
        }
    }

    /**
     * التحقق من صلاحية المستخدم للإجابة
     */
    protected function canUserRespond(Survey $survey, User $user): bool
    {
        $audience = $survey->getSetting('audience');

        if (! $audience) {
            return true; // لا قيود
        }

        // التحقق من الأقسام
        if (! empty($audience['department_ids'])) {
            if (! in_array($user->department_id, $audience['department_ids'])) {
                return false;
            }
        }

        // التحقق من الأدوار
        if (! empty($audience['role_names'])) {
            $hasRole = false;
            foreach ($audience['role_names'] as $role) {
                if ($user->hasRole($role)) {
                    $hasRole = true;
                    break;
                }
            }
            if (! $hasRole) {
                return false;
            }
        }

        // التحقق من المستخدمين المحددين
        if (! empty($audience['user_ids'])) {
            if (! in_array($user->id, $audience['user_ids'])) {
                return false;
            }
        }

        return true;
    }
}
