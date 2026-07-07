<?php

namespace Tests\Unit\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\DataTransform;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Services\TransformRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyEnumAndTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_type_metadata_covers_input_choice_datetime_file_matrix_and_display_behaviors(): void
    {
        foreach (FieldType::cases() as $fieldType) {
            $this->assertNotSame('', $fieldType->label());
            $this->assertStringStartsWith('heroicon-o-', $fieldType->icon());
        }

        $this->assertTrue(FieldType::Select->hasOptions());
        $this->assertTrue(FieldType::Radio->hasOptions());
        $this->assertTrue(FieldType::Checkbox->hasOptions());
        $this->assertTrue(FieldType::Multiselect->hasOptions());
        $this->assertFalse(FieldType::Text->hasOptions());
        $this->assertTrue(FieldType::Matrix->hasMatrixConfig());
        $this->assertFalse(FieldType::Date->hasMatrixConfig());
        $this->assertTrue(FieldType::Heading->isDisplayOnly());
        $this->assertTrue(FieldType::Separator->isDisplayOnly());
        $this->assertFalse(FieldType::Heading->storesValue());
        $this->assertTrue(FieldType::Email->storesValue());
        $this->assertTrue(FieldType::Checkbox->isMultiValue());
        $this->assertTrue(FieldType::Multiselect->isMultiValue());
        $this->assertTrue(FieldType::Matrix->isMultiValue());
        $this->assertFalse(FieldType::Text->isMultiValue());
    }

    public function test_data_transforms_apply_safely_and_scope_lookup_by_organization(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance Department',
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'owner@example.test',
        ]);
        Department::factory()->create(['name' => 'Finance Department']);

        $this->assertSame('trimmed', DataTransform::Trim->apply('  trimmed  '));
        $this->assertSame('mixed', DataTransform::Lowercase->apply('MIXED'));
        $this->assertSame('MIXED', DataTransform::Uppercase->apply('mixed'));
        $this->assertSame('+966512345678', DataTransform::NormalizePhone->apply('051 234 5678'));
        $this->assertSame('user@example.test', DataTransform::NormalizeEmail->apply(' User@Example.TEST '));
        $this->assertSame($department->id, DataTransform::MapDepartmentByName->apply('Finance Department', $organization->id));
        $this->assertSame($user->id, DataTransform::MapUserByEmail->apply(' OWNER@EXAMPLE.TEST ', $organization->id));
        $this->assertSame('2026-06-15', DataTransform::ParseDate->apply('2026/06/15'));
        $this->assertNull(DataTransform::ParseDate->apply('not-a-date'));
        $this->assertSame(42, DataTransform::ToInteger->apply('42'));
        $this->assertTrue(DataTransform::ToBoolean->apply(1));
        $this->assertNull(DataTransform::Trim->apply(null));

        $this->assertSame('VALUE', DataTransform::applyTransforms('  value  ', ['trim', 'uppercase']));
        foreach (DataTransform::cases() as $transform) {
            $this->assertNotSame('', $transform->label());
        }
    }

    public function test_transform_registry_lists_validates_applies_and_filters_by_field_type(): void
    {
        $available = TransformRegistry::getAvailableTransforms();

        $this->assertArrayHasKey('trim', $available);
        $this->assertSame('trimmed', TransformRegistry::apply('trim', '  trimmed  '));
        $this->assertSame('unchanged', TransformRegistry::apply('unknown', 'unchanged'));
        $this->assertSame('VALUE', TransformRegistry::applyMany(['trim', 'uppercase'], ' value '));
        $this->assertSame(['تحويل غير معروف: bad_transform'], TransformRegistry::validateTransforms(['trim', 'bad_transform']));
        $this->assertEmpty(TransformRegistry::validateTransforms(['trim', 'lowercase']));

        $this->assertContains(DataTransform::NormalizeEmail, TransformRegistry::getTransformsForFieldType('email'));
        $this->assertContains(DataTransform::NormalizePhone, TransformRegistry::getTransformsForFieldType('phone'));
        $this->assertContains(DataTransform::ToInteger, TransformRegistry::getTransformsForFieldType('number'));
        $this->assertContains(DataTransform::ParseDate, TransformRegistry::getTransformsForFieldType('date'));
        $this->assertContains(DataTransform::MapDepartmentByName, TransformRegistry::getTransformsForFieldType('select'));
        $this->assertSame([DataTransform::ToBoolean], TransformRegistry::getTransformsForFieldType('checkbox'));
        $this->assertSame([DataTransform::Trim], TransformRegistry::getTransformsForFieldType('file'));
    }

    public function test_survey_type_metadata_and_import_behavior(): void
    {
        $this->assertSame('استبيان أولي', SurveyType::Initial->label());
        $this->assertSame('استبيان دوري', SurveyType::Periodic->label());
        $this->assertStringContainsString('البيانات الأساسية', SurveyType::Initial->description());
        $this->assertStringContainsString('الدوري', SurveyType::Periodic->description());
        $this->assertTrue(SurveyType::Initial->createsImportRequest());
        $this->assertFalse(SurveyType::Periodic->createsImportRequest());
    }
}
