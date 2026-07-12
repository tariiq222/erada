<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskOrganizationScopeTest — عزل المؤسسة وصلاحية الحذف في إدارة المخاطر
 *
 * يعتمد على محرّك AuthZ الموحّد:
 *  - super_admin يتجاوز كل شيء (RiskPolicy::before)
 *  - مستخدمو المؤسسة A لا يستطيعون قراءة/حذف مخاطر مؤسسة B (عزل المؤسسة)
 *  - مستخدم يحمل RISKS_VIEW فقط (لا RISKS_DELETE) لا يستطيع حذف المخاطر
 */
class RiskOrganizationScopeTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_non_super_admin_cannot_read_other_org_risk(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        // مستخدم مؤسسة A يحمل RISKS_VIEW عبر المحرّك — لا يُسمح له بقراءة
        // مخاطر مؤسسة B بسبب عزل المؤسسة في AccessDecision.
        $adminA = User::factory()->create(['organization_id' => $orgA->id, 'is_active' => true]);
        $this->grantEngineCapability($adminA, Capability::RISKS_VIEW);

        $riskB = Risk::factory()->forOrganization($orgB)->create();

        $headers = [
            'Authorization' => 'Bearer '.$adminA->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->getJson("/api/risk-management/risks/{$riskB->id}", $headers)
            ->assertForbidden();
    }

    public function test_super_admin_can_read_any_org_risk(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $orgA = Organization::factory()->create();
        $riskA = Risk::factory()->forOrganization($orgA)->create();

        $headers = [
            'Authorization' => 'Bearer '.$superAdmin->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->getJson("/api/risk-management/risks/{$riskA->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $riskA->id);
    }

    public function test_view_only_user_cannot_delete_risk(): void
    {
        // مستخدم يحمل RISKS_VIEW فقط (بدون RISKS_DELETE) — لا يُسمح له بالحذف.
        // ملاحظة: دور admin يحمل RISKS_DELETE عبر scoped_role_definitions
        // (الـ migration الموحّد)، فلم يعد صالحاً لتمثيل مستخدم بلا صلاحية حذف.
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::RISKS_VIEW);

        $risk = Risk::factory()->forOrganization($org)->create();

        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->deleteJson("/api/risk-management/risks/{$risk->id}", [], $headers)
            ->assertForbidden();
    }
}
