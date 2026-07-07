<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyVersion;
use Illuminate\Database\Eloquent\Collection;

class VersioningService
{
    /**
     * إنشاء snapshot للاستبيان وقفله
     */
    public function createVersionAndLock(Survey $survey): SurveyVersion
    {
        // إنشاء الـ snapshot
        $version = SurveyVersion::createFromSurvey($survey);

        // قفل الاستبيان
        $survey->locked_at = now();
        $survey->save();

        return $version;
    }

    /**
     * الحصول على النسخة الحالية أو إنشاء واحدة جديدة
     */
    public function getOrCreateVersion(Survey $survey): SurveyVersion
    {
        // إذا كان الاستبيان مقفول، استخدم آخر نسخة
        if ($survey->isLocked()) {
            return $survey->latestVersion ?? SurveyVersion::createFromSurvey($survey);
        }

        // إنشاء نسخة جديدة
        return SurveyVersion::createFromSurvey($survey);
    }

    /**
     * حساب hash للاستبيان الحالي
     */
    public function calculateHash(Survey $survey): string
    {
        $data = [
            'survey_id' => $survey->id,
            'revision' => $survey->revision,
            'sections' => $survey->sections->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'order' => $s->order,
            ])->toArray(),
            'fields' => $survey->fields->map(fn ($f) => [
                'id' => $f->id,
                'field_key' => $f->field_key,
                'type' => $f->type,
                'config' => $f->config,
            ])->toArray(),
        ];

        return hash('sha256', json_encode($data));
    }

    /**
     * التحقق من تطابق الـ hash
     */
    public function validateVersionHash(Survey $survey, string $providedHash): bool
    {
        // ابحث عن النسخة بالـ hash
        $version = SurveyVersion::where('survey_id', $survey->id)
            ->where('version_hash', $providedHash)
            ->first();

        return $version !== null;
    }

    /**
     * الحصول على النسخة بالـ hash
     */
    public function getVersionByHash(string $hash): ?SurveyVersion
    {
        return SurveyVersion::where('version_hash', $hash)->first();
    }

    /**
     * إنشاء revision جديد من الاستبيان
     */
    public function createNewRevision(Survey $survey): Survey
    {
        return $survey->createNewRevision();
    }

    /**
     * الحصول على الاستبيان المنشور الأخير بالكود
     */
    public function getLatestPublishedByCode(string $code): ?Survey
    {
        return Survey::where('code', $code)
            ->where('status', SurveyStatus::Published)
            ->with(['sections.fields', 'fields'])
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * الحصول على revision معين
     */
    public function getRevision(string $code, int $revision): ?Survey
    {
        return Survey::where('code', $code)
            ->where('revision', $revision)
            ->with(['sections.fields', 'fields'])
            ->first();
    }

    /**
     * الحصول على كل النسخ
     */
    public function getAllRevisions(string $code): Collection
    {
        return Survey::where('code', $code)
            ->orderBy('revision')
            ->get();
    }
}
