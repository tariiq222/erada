<?php

namespace Database\Seeders\Mock;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\MilestoneDeliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectRisk;
use App\Modules\Projects\Models\Stakeholder;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Seeder;

class ProjectsSeeder extends Seeder
{
    public array $projects = [];

    public function run(array $users, array $departments, array $programs): void
    {
        $projectsData = [
            ['name' => 'تطوير نظام إدارة الموارد البشرية', 'description' => 'تطوير نظام متكامل لإدارة الموارد البشرية يشمل التوظيف والرواتب والإجازات والتقييم', 'status' => 'in_progress', 'priority' => 'high', 'budget' => 850000, 'progress' => 45],
            ['name' => 'إعادة تصميم البوابة الإلكترونية', 'description' => 'تحديث وتطوير البوابة الإلكترونية للمنظمة بتصميم عصري وتجربة مستخدم محسنة', 'status' => 'in_progress', 'priority' => 'critical', 'budget' => 650000, 'progress' => 70],
            ['name' => 'نظام إدارة العقود والمشتريات', 'description' => 'تطوير نظام إلكتروني لإدارة العقود والمشتريات والموردين', 'status' => 'planning', 'priority' => 'medium', 'budget' => 480000, 'progress' => 10],
            ['name' => 'تطبيق الجوال للموظفين', 'description' => 'تطوير تطبيق جوال للموظفين للوصول للخدمات الذاتية والإشعارات', 'status' => 'completed', 'priority' => 'high', 'budget' => 320000, 'progress' => 100],
            ['name' => 'منصة التقارير والتحليلات', 'description' => 'بناء منصة تقارير وتحليلات متقدمة لدعم اتخاذ القرار باستخدام BI', 'status' => 'in_progress', 'priority' => 'high', 'budget' => 720000, 'progress' => 35],
            ['name' => 'ترقية البنية التحتية السحابية', 'description' => 'ترقية وتحسين البنية التحتية السحابية وزيادة الأمان والأداء', 'status' => 'on_hold', 'priority' => 'medium', 'budget' => 1200000, 'progress' => 25],
            ['name' => 'نظام إدارة المخاطر المؤسسية', 'description' => 'تطوير نظام متكامل لإدارة ومتابعة المخاطر المؤسسية', 'status' => 'draft', 'priority' => 'low', 'budget' => 280000, 'progress' => 0],
            ['name' => 'أتمتة العمليات الإدارية RPA', 'description' => 'أتمتة العمليات الإدارية الروتينية باستخدام تقنيات RPA', 'status' => 'in_progress', 'priority' => 'medium', 'budget' => 420000, 'progress' => 55],
            ['name' => 'نظام إدارة الوثائق الإلكتروني', 'description' => 'تطوير نظام لإدارة الوثائق والأرشفة الإلكترونية', 'status' => 'in_progress', 'priority' => 'medium', 'budget' => 350000, 'progress' => 40],
            ['name' => 'بوابة الخدمات الذاتية', 'description' => 'تطوير بوابة خدمات ذاتية للموظفين والمستفيدين', 'status' => 'planning', 'priority' => 'high', 'budget' => 550000, 'progress' => 15],
        ];

        $deptIds = array_map(fn ($d) => $d->id, $departments);
        $pmUsers = array_filter($users, fn ($user) => str_contains((string) $user->job_title, 'مشروع'));
        $pmUsers = array_values($pmUsers);

        foreach ($projectsData as $index => $data) {
            $startDate = now()->subDays(rand(30, 180));
            $endDate = now()->addDays(rand(30, 365));

            // اختيار مدير المشروع — يُمثَّل الآن كدور سياقي (scoped role) لا كعمود
            $manager = count($pmUsers) > 0 ? $pmUsers[array_rand($pmUsers)] : $users[2];

            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'objectives' => ['تحقيق الهدف الرئيسي للمشروع', 'تحسين الكفاءة', 'رفع مستوى الخدمة'],
                'in_scope' => ['نطاق العمل الأساسي', 'المتطلبات الوظيفية', 'التكامل مع الأنظمة'],
                'out_of_scope' => ['التحديثات المستقبلية', 'الصيانة طويلة المدى'],
                'status' => $data['status'],
                'priority' => $data['priority'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'actual_start_date' => $data['status'] !== 'draft' ? $startDate->copy()->addDays(rand(0, 14)) : null,
                'budget' => $data['budget'],
                'spent_amount' => $data['budget'] * ($data['progress'] / 100) * rand(70, 100) / 100,
                'progress' => $data['progress'],
                'department_id' => $deptIds[array_rand($deptIds)],
                'program_id' => count($programs) > 0 ? $programs[array_rand($programs)]->id : null,
                'created_by' => $users[0]->id,
            ]);

            $this->assignProjectRole($manager->id, 'manager', $project);

            $this->projects[] = $project;

            $this->createMilestonesAndTasks($project, $users);
            $this->createProjectTeam($project, $users, $manager);
            $this->createProjectRisksAndExpenses($project, $users);
            $this->createStakeholders($project, $users, $departments);
        }
    }

    private function createMilestonesAndTasks(Project $project, array $users): void
    {
        $milestonesTemplates = [
            ['name' => 'مرحلة التحليل والتصميم', 'weight' => 20],
            ['name' => 'مرحلة التطوير', 'weight' => 40],
            ['name' => 'مرحلة الاختبار', 'weight' => 25],
            ['name' => 'مرحلة الإطلاق والتسليم', 'weight' => 15],
        ];

        $tasksPerMilestone = [
            ['جمع المتطلبات', 'تحليل المتطلبات الوظيفية', 'تصميم قاعدة البيانات', 'تصميم واجهة المستخدم', 'مراجعة التصميم'],
            ['تطوير الواجهة الخلفية', 'تطوير الواجهة الأمامية', 'تطوير APIs', 'ربط الأنظمة', 'مراجعة الكود'],
            ['اختبار الوحدات', 'اختبار التكامل', 'اختبار الأداء', 'اختبار الأمان', 'إصلاح الأخطاء'],
            ['إعداد بيئة الإنتاج', 'نقل البيانات', 'تدريب المستخدمين', 'التوثيق', 'التسليم النهائي'],
        ];

        $milestoneStatuses = ['pending', 'in_progress', 'completed', 'overdue'];
        $taskStatuses = ['todo', 'in_progress', 'in_review', 'completed'];
        $taskPriorities = ['low', 'medium', 'high', 'critical'];

        foreach ($milestonesTemplates as $mIndex => $mTemplate) {
            $startDate = $project->start_date->copy()->addDays($mIndex * 45);
            $dueDate = $startDate->copy()->addDays(40);

            $mStatus = $milestoneStatuses[rand(0, 3)];
            $mProgress = match ($mStatus) {
                'completed' => 100,
                'in_progress' => rand(30, 80),
                'overdue' => rand(10, 50),
                default => 0,
            };

            $milestone = Milestone::create([
                'project_id' => $project->id,
                'name' => $mTemplate['name'],
                'description' => 'وصف '.$mTemplate['name'].' لمشروع '.$project->name,
                'start_date' => $startDate,
                'due_date' => $dueDate,
                'status' => $mStatus,
                'progress' => $mProgress,
                'order' => $mIndex + 1,
                'completed_date' => $mStatus === 'completed' ? now()->subDays(rand(1, 30)) : null,
            ]);

            // إنشاء التسليمات
            $deliverables = ['تقرير', 'وثيقة', 'نموذج', 'نظام فرعي'];
            foreach (array_slice($deliverables, 0, rand(1, 3)) as $dIndex => $deliverable) {
                MilestoneDeliverable::create([
                    'milestone_id' => $milestone->id,
                    'name' => $deliverable.' '.($dIndex + 1),
                    'description' => 'تسليم: '.$deliverable,
                    'status' => $mStatus === 'completed' ? 'completed' : (['pending', 'in_progress'][rand(0, 1)]),
                    'order' => $dIndex + 1,
                ]);
            }

            // إنشاء المهام
            $tasks = $tasksPerMilestone[$mIndex];
            foreach ($tasks as $tIndex => $taskTitle) {
                $tStatus = $taskStatuses[rand(0, 3)];
                $tProgress = match ($tStatus) {
                    'completed' => 100,
                    'in_review' => rand(80, 95),
                    'in_progress' => rand(20, 70),
                    default => 0,
                };

                Task::create([
                    'project_id' => $project->id,
                    'milestone_id' => $milestone->id,
                    'title' => $taskTitle,
                    'description' => 'تفاصيل مهمة: '.$taskTitle,
                    'status' => $tStatus,
                    'priority' => $taskPriorities[rand(0, 3)],
                    'due_date' => $dueDate->copy()->subDays(rand(0, 10)),
                    'estimated_hours' => rand(8, 80),
                    'actual_hours' => $tStatus === 'completed' ? rand(8, 100) : rand(0, 40),
                    'progress' => $tProgress,
                    'assigned_to' => $users[rand(5, count($users) - 1)]->id,
                ]);
            }
        }
    }

    private function createProjectTeam(Project $project, array $users, $manager): void
    {
        $roles = ['member', 'viewer'];
        $memberCount = rand(3, 6);
        // المدير مُسجَّل مسبقاً كدور scoped — استبعده من أعضاء الفريق
        $usedUserIds = [$manager->id];

        for ($i = 0; $i < $memberCount; $i++) {
            $user = $users[rand(5, count($users) - 1)];

            if (in_array($user->id, $usedUserIds)) {
                continue;
            }

            $usedUserIds[] = $user->id;
            $rawRole = $roles[array_rand($roles)];

            $this->assignProjectRole($user->id, $rawRole, $project);
        }
    }

    private function assignProjectRole(int $userId, string $roleName, Project $project): void
    {
        $role = AuthorizationRole::query()->where('name', $roleName)->firstOrFail();
        $organizationId = $project->department()->value('organization_id');

        AuthorizationRoleAssignment::query()->updateOrCreate(
            [
                'authorization_role_id' => $role->id,
                'user_id' => $userId,
                'scope_type' => 'project',
                'scope_id' => $project->id,
            ],
            [
                'organization_id' => $organizationId,
                'inherit_to_children' => false,
                'expires_at' => null,
                'source' => 'auto',
                'granted_by' => null,
            ],
        );
    }

    private function createProjectRisksAndExpenses(Project $project, array $users): void
    {
        // المخاطر
        $riskTemplates = [
            ['risk' => 'تأخر في تسليم المورد', 'probability' => 'medium', 'impact' => 'high'],
            ['risk' => 'نقص الموارد البشرية', 'probability' => 'high', 'impact' => 'medium'],
            ['risk' => 'تغيير المتطلبات', 'probability' => 'high', 'impact' => 'high'],
            ['risk' => 'مشاكل تقنية غير متوقعة', 'probability' => 'low', 'impact' => 'high'],
            ['risk' => 'تجاوز الميزانية', 'probability' => 'medium', 'impact' => 'high'],
        ];

        $riskCount = rand(2, 4);
        shuffle($riskTemplates);

        for ($i = 0; $i < $riskCount; $i++) {
            $template = $riskTemplates[$i];
            ProjectRisk::create([
                'project_id' => $project->id,
                'risk' => $template['risk'],
                'probability' => $template['probability'],
                'impact' => $template['impact'],
                'response' => 'خطة استجابة للتعامل مع المخاطر وإجراءات التخفيف من المخاطر',
                'status' => ['open', 'mitigated', 'closed'][rand(0, 2)],
                'order' => $i + 1,
            ]);
        }

        // المصروفات
        $expenseCategories = ['human_resources', 'materials', 'services', 'operational', 'training', 'travel', 'other'];
        $expenseCount = rand(3, 8);

        for ($i = 0; $i < $expenseCount; $i++) {
            ProjectExpense::create([
                'project_id' => $project->id,
                'title' => 'مصروف '.($i + 1),
                'description' => 'وصف المصروف',
                'amount' => rand(5000, 100000),
                'category' => $expenseCategories[array_rand($expenseCategories)],
                'expense_date' => now()->subDays(rand(1, 90)),
                'reference_number' => 'EXP-'.rand(1000, 9999),
                'created_by' => $users[rand(2, count($users) - 1)]->id,
            ]);
        }

        // KPIs (نظام Performance)
        $kpiTemplates = ['نسبة الإنجاز', 'مؤشر الأداء الزمني', 'مؤشر أداء التكلفة', 'عدد المهام المنجزة'];

        foreach ($kpiTemplates as $index => $indicator) {
            $kpi = (new Kpi)->forceFill([
                'organization_id' => $project->organization_id,
                'name' => $indicator,
                'measurement_method' => 'قياس دوري',
                'category' => 'project',
                'baseline' => rand(20, 40),
                'target' => rand(80, 100),
                'current_value' => rand(40, 95),
                'frequency' => 'monthly',
                'direction' => 'increase',
                'status' => 'active',
                'owner_id' => $project->created_by,
                'created_by' => $project->created_by,
                'order' => $index + 1,
            ]);
            $kpi->save();

            (new KpiLink)->forceFill([
                'organization_id' => $project->organization_id,
                'kpi_id' => $kpi->id,
                'linkable_type' => Project::class,
                'linkable_id' => $project->id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $project->created_by,
            ])->save();
        }
    }

    private function createStakeholders(Project $project, array $users, array $departments): void
    {
        $stakeholderTemplates = [
            ['role' => 'governance', 'influence' => 'high', 'interest' => 'high'],
            ['role' => 'end_user', 'influence' => 'medium', 'interest' => 'high'],
            ['role' => 'consultant', 'influence' => 'medium', 'interest' => 'medium'],
            ['role' => 'implementer', 'influence' => 'low', 'interest' => 'high'],
            ['role' => 'influencer', 'influence' => 'high', 'interest' => 'medium'],
        ];

        $count = rand(2, 4);
        shuffle($stakeholderTemplates);

        for ($i = 0; $i < $count; $i++) {
            $template = $stakeholderTemplates[$i];
            $user = $users[rand(0, count($users) - 1)];

            Stakeholder::create([
                'project_id' => $project->id,
                'user_id' => rand(0, 1) ? $user->id : null,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => '05'.rand(10000000, 99999999),
                'organization' => $departments[rand(0, count($departments) - 1)]->name,
                'role' => $template['role'],
                'influence' => $template['influence'],
                'interest' => $template['interest'],
                'notes' => 'ملاحظات حول صاحب المصلحة',
            ]);
        }
    }
}
