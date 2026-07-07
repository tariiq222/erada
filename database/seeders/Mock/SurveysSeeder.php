<?php

namespace Database\Seeders\Mock;

use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Database\Seeder;

class SurveysSeeder extends Seeder
{
    public array $surveys = [];

    public function run(array $users): void
    {
        $surveysData = [
            [
                'title' => 'استبيان رضا الموظفين السنوي',
                'description' => 'استبيان لقياس مستوى رضا الموظفين عن بيئة العمل والخدمات المقدمة',
                'type' => 'periodic',
                'status' => 'published',
                'category' => 'hr',
            ],
            [
                'title' => 'استبيان تقييم الخدمات التقنية',
                'description' => 'تقييم جودة الخدمات التقنية المقدمة من إدارة تقنية المعلومات',
                'type' => 'periodic',
                'status' => 'published',
                'category' => 'it',
            ],
            [
                'title' => 'استبيان التدريب والتطوير',
                'description' => 'استبيان لتحديد الاحتياجات التدريبية للموظفين',
                'type' => 'initial',
                'status' => 'draft',
                'category' => 'training',
            ],
            [
                'title' => 'استبيان رضا المستفيدين',
                'description' => 'قياس رضا المستفيدين عن الخدمات المقدمة',
                'type' => 'periodic',
                'status' => 'published',
                'category' => 'customer',
            ],
            [
                'title' => 'تقييم مشروع التحول الرقمي',
                'description' => 'استبيان لجمع آراء المستفيدين حول مشروع التحول الرقمي',
                'type' => 'initial',
                'status' => 'published',
                'category' => 'project',
            ],
        ];

        foreach ($surveysData as $data) {
            $survey = Survey::create([
                'title' => $data['title'],
                'description' => $data['description'],
                'type' => $data['type'],
                'status' => $data['status'],
                'category' => $data['category'],
                'is_public' => rand(0, 1),
                'requires_auth' => rand(0, 1),
                'allow_multiple_responses' => false,
                'allow_edit_response' => true,
                'consent_required' => true,
                'consent_text' => 'أوافق على المشاركة في هذا الاستبيان وأفهم أن إجاباتي ستكون سرية.',
                'welcome_message' => 'مرحباً بك في '.$data['title'],
                'thank_you_message' => 'شكراً لمشاركتك! إجاباتك ستساعدنا في التحسين المستمر.',
                'published_at' => $data['status'] === 'published' ? now()->subDays(rand(1, 30)) : null,
                'starts_at' => now()->subDays(rand(1, 30)),
                'ends_at' => now()->addDays(rand(30, 90)),
                'created_by' => $users[0]->id,
            ]);

            $this->surveys[] = $survey;

            $this->createSurveySectionsAndFields($survey);

            if ($data['status'] === 'published') {
                $this->createSurveyResponses($survey, $users);
            }
        }
    }

    private function createSurveySectionsAndFields(Survey $survey): void
    {
        $sectionsData = [
            [
                'title' => 'معلومات عامة',
                'fields' => [
                    ['label' => 'القسم/الإدارة', 'type' => 'select', 'required' => true],
                    ['label' => 'سنوات الخبرة', 'type' => 'select', 'required' => true],
                    ['label' => 'المسمى الوظيفي', 'type' => 'text', 'required' => false],
                ],
            ],
            [
                'title' => 'التقييم العام',
                'fields' => [
                    ['label' => 'ما مدى رضاك العام؟', 'type' => 'rating', 'required' => true],
                    ['label' => 'هل توصي بالخدمة للآخرين؟', 'type' => 'scale', 'required' => true],
                    ['label' => 'أبرز نقاط القوة', 'type' => 'textarea', 'required' => false],
                    ['label' => 'مجالات التحسين', 'type' => 'textarea', 'required' => false],
                ],
            ],
            [
                'title' => 'ملاحظات إضافية',
                'fields' => [
                    ['label' => 'هل لديك أي اقتراحات؟', 'type' => 'textarea', 'required' => false],
                    ['label' => 'هل ترغب في التواصل؟', 'type' => 'radio', 'required' => true],
                ],
            ],
        ];

        $fieldOrder = 1;

        foreach ($sectionsData as $sIndex => $sectionData) {
            $section = SurveySection::create([
                'survey_id' => $survey->id,
                'title' => $sectionData['title'],
                'description' => 'وصف القسم: '.$sectionData['title'],
                'order' => $sIndex + 1,
                'is_visible' => true,
            ]);

            foreach ($sectionData['fields'] as $fIndex => $fieldData) {
                $fieldKey = 'field_'.$section->id.'_'.($fIndex + 1);

                $config = [];
                if ($fieldData['type'] === 'select' || $fieldData['type'] === 'radio') {
                    $config['options'] = [
                        ['label' => 'الخيار الأول', 'value' => 'option_1'],
                        ['label' => 'الخيار الثاني', 'value' => 'option_2'],
                        ['label' => 'الخيار الثالث', 'value' => 'option_3'],
                    ];
                } elseif ($fieldData['type'] === 'rating') {
                    $config['max_rating'] = 5;
                } elseif ($fieldData['type'] === 'scale') {
                    $config['min'] = 0;
                    $config['max'] = 10;
                    $config['labels'] = ['غير محتمل', 'محتمل جداً'];
                }

                SurveyField::create([
                    'survey_id' => $survey->id,
                    'section_id' => $section->id,
                    'field_key' => $fieldKey,
                    'name' => $fieldKey,
                    'label' => $fieldData['label'],
                    'description' => null,
                    'type' => $fieldData['type'],
                    'config' => $config,
                    'is_required' => $fieldData['required'],
                    'is_visible' => true,
                    'order' => $fieldOrder++,
                ]);
            }
        }
    }

    private function createSurveyResponses(Survey $survey, array $users): void
    {
        $responseCount = rand(15, 40);
        $fields = $survey->fields;

        for ($i = 0; $i < $responseCount; $i++) {
            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'respondent_type' => rand(0, 1) ? 'user' : 'public',
                'respondent_id' => rand(0, 1) ? $users[rand(0, count($users) - 1)]->id : null,
                'respondent_name' => rand(0, 1) ? 'مشارك '.($i + 1) : null,
                'respondent_email' => rand(0, 1) ? 'respondent'.($i + 1).'@example.com' : null,
                'status' => 'submitted',
                'submitted_at' => now()->subDays(rand(1, 30)),
                'completion_time' => rand(120, 900),
            ]);

            foreach ($fields as $field) {
                $fieldType = $field->type instanceof \BackedEnum ? $field->type->value : (string) $field->type;
                $value = $this->generateAnswerValue($fieldType);

                $response->answers()->create([
                    'field_id' => $field->id,
                    'field_key' => $field->field_key,
                    'answer_value' => $value,
                    'answer_text' => is_string($value) ? $value : null,
                    'answer_number' => is_numeric($value) ? $value : null,
                ]);
            }
        }
    }

    private function generateAnswerValue(string $fieldType): mixed
    {
        return match ($fieldType) {
            'text' => 'إجابة نصية قصيرة',
            'textarea' => 'إجابة نصية طويلة تحتوي على تفاصيل أكثر حول الموضوع المطروح في السؤال.',
            'number' => rand(1, 100),
            'rating' => rand(1, 5),
            'scale' => rand(0, 10),
            'select', 'radio' => 'option_'.rand(1, 3),
            'checkbox' => ['option_1', 'option_2'],
            'date' => now()->subDays(rand(1, 365))->format('Y-m-d'),
            'email' => 'test@example.com',
            'phone' => '05'.rand(10000000, 99999999),
            default => 'قيمة افتراضية',
        };
    }
}
