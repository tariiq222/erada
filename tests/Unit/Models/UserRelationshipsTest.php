<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class UserRelationshipsTest extends TestCase
{
    // ملاحظة: علاقات supervisedProjects()/sponsoredProjects()/managedProjects()
    // حُذفت بعد توحيد أدوار المشاريع إلى الأدوار السياقية (scoped roles)؛
    // لم يعد للمشروع أعمدة manager_id/supervisor_id/sponsor_id.
    //
    // كذلك علاقات ownedPrograms()/managedPrograms()/sponsoredPrograms()/
    // ownedPortfolios() حُذفت في المرحلة (هـ) من توحيد الصلاحيات: أدوار
    // Strategy صارت أدوار عنصر inline سياقية، وأُسقطت أعمدة
    // programs.owner_id/program_manager_id/executive_sponsor_id و
    // portfolios.portfolio_owner_id.

    public function test_user_has_stakeholder_roles_relationship(): void
    {
        $user = new User;
        $this->assertInstanceOf(HasMany::class, $user->stakeholderRoles());
    }

    public function test_user_has_created_expenses_relationship(): void
    {
        $user = new User;
        $this->assertInstanceOf(HasMany::class, $user->createdExpenses());
    }

    public function test_user_has_owned_kpis_relationship(): void
    {
        $user = new User;
        $this->assertInstanceOf(HasMany::class, $user->ownedKpis());
    }
}
