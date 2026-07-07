<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class OrganizationSurveysRelationshipTest extends TestCase
{
    public function test_organization_has_surveys_relationship(): void
    {
        $org = new Organization;
        $this->assertInstanceOf(HasMany::class, $org->surveys());
    }

    public function test_organization_surveys_uses_correct_foreign_key(): void
    {
        $org = new Organization;
        $relation = $org->surveys();
        $this->assertEquals('organization_id', $relation->getForeignKeyName());
    }

    public function test_organization_surveys_returns_survey_model(): void
    {
        $org = new Organization;
        $this->assertInstanceOf(Survey::class, $org->surveys()->getRelated());
    }
}
