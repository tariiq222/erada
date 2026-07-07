<?php

namespace Database\Seeders\Scenarios;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportableType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class HospitalScenario
{
    public array $departments = [];

    public array $users = [];

    public ?Organization $organization = null;

    private array $deptCodeMap = [];

    public function __construct(private readonly Command $command) {}

    public function run(): void
    {
        $this->command->info('[Hospital] Cleaning previous demo data...');
        $this->cleanPreviousMockData();

        $this->command->info('[Hospital] Creating organization...');
        $this->createOrganization();

        $this->command->info('[Hospital] Creating department hierarchy (4 levels)...');
        $this->createDepartments();

        $this->command->info('[Hospital] Creating users...');
        $this->createUsers();

        $this->command->info('[Hospital] Assigning department managers...');
        $this->assignDepartmentManagers();

        $this->command->info('[Hospital] Seeding OVR incident types...');
        $this->seedIncidentTypes();

        $this->command->info('');
        $this->command->info('Hospital scenario complete.');
        $this->command->info('  Organization : '.$this->organization->name);
        $this->command->info('  Departments  : '.count($this->departments));
        $this->command->info('  Users        : '.count($this->users));
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('  Admin:          admin@admin.com / password');
        $this->command->info('  PMO head:       pmo.head@alnoor.sa / password');
        $this->command->info('  Planning dir:   trans.director@alnoor.sa / password');
    }

    private function cleanPreviousMockData(): void
    {
        DB::statement('SET session_replication_role = replica');

        $tablesToClean = [
            'project_risks', 'project_expenses', 'stakeholders',
            'milestone_deliverables', 'milestones', 'tasks', 'comments',
            'attachments', 'project_activities', 'projects', 'programs',
            'portfolios', 'decisions', 'reviews', 'survey_responses',
            'survey_invitations', 'survey_sections', 'survey_fields',
            'surveys', 'data_imports', 'data_mappings', 'activity_logs',
            'scoped_roles',
        ];

        foreach ($tablesToClean as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        if (DB::getSchemaBuilder()->hasTable('departments')) {
            DB::table('departments')->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('users')) {
            DB::table('users')->whereNotIn('email', [
                'admin@admin.com', 'manager@admin.com', 'pm@admin.com',
            ])->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('organizations')) {
            DB::table('organizations')->where('code', '!=', 'DEFAULT')->delete();
        }

        DB::statement('SET session_replication_role = origin');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createOrganization(): void
    {
        $this->organization = Organization::create([
            'name' => 'مستشفى النور التخصصي',
            'code' => 'ALNOOR-HOSP',
            'description' => 'مستشفى تخصصي يقدم خدمات طبية متكاملة ومشاريع تحول مؤسسي',
            'email' => 'info@alnoor-hospital.sa',
            'phone' => '+966112345678',
            'address' => 'الرياض - المملكة العربية السعودية',
            'website' => 'https://alnoor-hospital.sa',
            'is_active' => true,
            'settings' => [
                'system' => [
                    'date_format' => 'DD/MM/YYYY',
                    'time_format' => '24h',
                    'timezone' => 'Asia/Riyadh',
                    'default_language' => 'ar',
                ],
            ],
        ]);
    }

    private function createDepartments(): void
    {
        $codeMap = [];

        $sectors = [
            ['name' => 'القطاع الطبي', 'code' => 'SECTOR-MED', 'description' => 'القطاع الطبي ويضم جميع التخصصات والإدارات الطبية'],
            ['name' => 'القطاع الإداري والمالي', 'code' => 'SECTOR-ADM', 'description' => 'القطاع الإداري والمالي ويضم الإدارات الداعمة'],
            ['name' => 'قطاع التشغيل والخدمات', 'code' => 'SECTOR-OPS', 'description' => 'قطاع التشغيل والخدمات المساندة'],
        ];

        foreach ($sectors as $s) {
            $dept = Department::create([
                'name' => $s['name'],
                'code' => $s['code'],
                'description' => $s['description'],
                'level' => Department::LEVEL_TOP_MANAGEMENT,
                'parent_id' => null,
                'organization_id' => $this->organization->id,
                'is_active' => true,
            ]);
            $codeMap[$s['code']] = $dept->id;
            $this->departments[] = $dept;
        }

        $executives = [
            ['name' => 'الإدارة الطبية', 'code' => 'MED-DIR', 'parent' => 'SECTOR-MED', 'description' => 'الإدارة العليا للشؤون الطبية'],
            ['name' => 'إدارة التمريض', 'code' => 'NUR-DIR', 'parent' => 'SECTOR-MED', 'description' => 'إدارة خدمات التمريض'],
            ['name' => 'إدارة التخطيط والتحول', 'code' => 'TRANS-DIR', 'parent' => 'SECTOR-ADM', 'description' => 'إدارة التخطيط الاستراتيجي والتحول المؤسسي - تضم مكتب المشاريع'],
            ['name' => 'إدارة الموارد البشرية', 'code' => 'HR-DIR', 'parent' => 'SECTOR-ADM', 'description' => 'إدارة شؤون الموظفين والتطوير'],
            ['name' => 'الإدارة المالية', 'code' => 'FIN-DIR', 'parent' => 'SECTOR-ADM', 'description' => 'الشؤون المالية والمحاسبة والميزانية'],
            ['name' => 'إدارة تقنية المعلومات', 'code' => 'IT-DIR', 'parent' => 'SECTOR-ADM', 'description' => 'إدارة التقنية والتحول الرقمي'],
            ['name' => 'إدارة الخدمات المساندة', 'code' => 'SUP-DIR', 'parent' => 'SECTOR-OPS', 'description' => 'الخدمات اللوجستية والصيانة والتشغيل'],
            ['name' => 'إدارة تجربة المريض', 'code' => 'PT-EXP-DIR', 'parent' => 'SECTOR-OPS', 'description' => 'تجربة المريض ورضا المراجعين'],
            ['name' => 'إدارة الجودة وسلامة المرضى', 'code' => 'QA-DIR', 'parent' => 'SECTOR-OPS', 'description' => 'الجودة وسلامة المرضى والاعتماد'],
        ];

        foreach ($executives as $e) {
            $dept = Department::create([
                'name' => $e['name'],
                'code' => $e['code'],
                'description' => $e['description'],
                'level' => Department::LEVEL_EXECUTIVE,
                'parent_id' => $codeMap[$e['parent']],
                'organization_id' => $this->organization->id,
                'is_active' => true,
            ]);
            $codeMap[$e['code']] = $dept->id;
            $this->departments[] = $dept;
        }

        // PMO branch (the key PMO path)
        $this->createDeptTree($codeMap, 'TRANS-DIR', [
            ['name' => 'مكتب المشاريع (PMO)', 'code' => 'PMO', 'description' => 'مكتب إدارة المشاريع', 'children' => [
                ['name' => 'قسم تخطيط المشاريع', 'code' => 'PMO-PLAN', 'description' => 'تخطيط وجدولة المشاريع'],
                ['name' => 'قسم متابعة وتقييم المشاريع', 'code' => 'PMO-MON', 'description' => 'متابعة الأداء وإعداد التقارير'],
                ['name' => 'قسم حوكمة المشاريع', 'code' => 'PMO-GOV', 'description' => 'حوكمة ومعايير إدارة المشاريع'],
            ]],
            ['name' => 'إدارة التخطيط الاستراتيجي', 'code' => 'STRAT', 'description' => 'إعداد الخطط الاستراتيجية ومتابعتها', 'children' => [
                ['name' => 'قسم التخطيط والميزانية', 'code' => 'STRAT-PLN', 'description' => 'التخطيط والميزانية السنوية'],
                ['name' => 'قسم قياس الأداء المؤسسي', 'code' => 'STRAT-KPI', 'description' => 'مؤشرات الأداء الرئيسية'],
            ]],
            ['name' => 'إدارة التحول المؤسسي', 'code' => 'TRANS', 'description' => 'برامج التحول وتطوير الأعمال', 'children' => [
                ['name' => 'قسم تطوير العمليات', 'code' => 'TRANS-PROC', 'description' => 'هندسة وتطوير العمليات'],
                ['name' => 'قسم إدارة التغيير', 'code' => 'TRANS-CHG', 'description' => 'إدارة التغيير المؤسسي'],
                ['name' => 'قسم الابتكار والتحسين المستمر', 'code' => 'TRANS-INN', 'description' => 'الابتكار والتحسين المستمر'],
            ]],
        ]);

        $this->createDeptTree($codeMap, 'MED-DIR', [
            ['name' => 'إدارة الطب الباطني', 'code' => 'INT-MED', 'description' => 'طب الباطنة', 'children' => [
                ['name' => 'قسم أمراض القلب', 'code' => 'CARD', 'description' => 'أمراض القلب والقسطرة'],
                ['name' => 'قسم الجهاز الهضمي', 'code' => 'GI', 'description' => 'أمراض الكبد والجهاز الهضمي'],
                ['name' => 'قسم أمراض الكلى', 'code' => 'NEPH', 'description' => 'أمراض الكلى'],
            ]],
            ['name' => 'إدارة الجراحة', 'code' => 'SURG', 'description' => 'الجراحات العامة والتخصصية', 'children' => [
                ['name' => 'قسم الجراحة العامة', 'code' => 'GEN-SURG', 'description' => 'الجراحة العامة'],
                ['name' => 'قسم جراحة العظام', 'code' => 'ORTHO', 'description' => 'جراحة العظام والمفاصل'],
            ]],
            ['name' => 'إدارة طب الطوارئ', 'code' => 'EMRG', 'description' => 'خدمات الطوارئ', 'children' => [
                ['name' => 'قسم طوارئ البالغين', 'code' => 'ER-ADULT', 'description' => 'طوارئ البالغين'],
                ['name' => 'قسم طوارئ الأطفال', 'code' => 'ER-PED', 'description' => 'طوارئ الأطفال'],
            ]],
            ['name' => 'إدارة المختبرات وبنك الدم', 'code' => 'LAB', 'description' => 'المختبرات الطبية وبنك الدم', 'children' => [
                ['name' => 'قسم المختبر السريري', 'code' => 'LAB-CLIN', 'description' => 'المختبر السريري'],
                ['name' => 'قسم بنك الدم', 'code' => 'BLOOD', 'description' => 'بنك الدم'],
            ]],
        ]);

        $this->createDeptTree($codeMap, 'NUR-DIR', [
            ['name' => 'إدارة خدمات التمريض', 'code' => 'NUR-SVC', 'description' => 'الخدمات التمريضية', 'children' => [
                ['name' => 'قسم تمريض الطوارئ', 'code' => 'NUR-ER', 'description' => 'تمريض الطوارئ'],
                ['name' => 'قسم تمريض الأقسام الداخلية', 'code' => 'NUR-IPD', 'description' => 'تمريض التنويم'],
            ]],
        ]);

        $this->createDeptTree($codeMap, 'QA-DIR', [
            ['name' => 'إدارة الجودة', 'code' => 'QA-MGT', 'description' => 'ضمان الجودة والاعتماد', 'children' => [
                ['name' => 'قسم اعتماد المنشآت الصحية', 'code' => 'QA-CBAHI', 'description' => 'تطبيق معايير CBAHI'],
            ]],
            ['name' => 'إدارة سلامة المرضى', 'code' => 'PS-MGT', 'description' => 'سلامة المرضى والحد من المخاطر', 'children' => [
                ['name' => 'قسم متابعة الأحداث السريرية', 'code' => 'PS-INC', 'description' => 'متابعة الحوادث الإكلينيكية'],
            ]],
        ]);

        $this->deptCodeMap = $codeMap;
        $this->command->info('   Departments created: '.count($this->departments));
    }

    private function createDeptTree(array &$codeMap, string $parentCode, array $children): void
    {
        foreach ($children as $deptData) {
            $dept = Department::create([
                'name' => $deptData['name'],
                'code' => $deptData['code'],
                'description' => $deptData['description'] ?? null,
                'level' => Department::LEVEL_DEPARTMENT,
                'parent_id' => $codeMap[$parentCode],
                'organization_id' => $this->organization->id,
                'is_active' => true,
            ]);
            $codeMap[$deptData['code']] = $dept->id;
            $this->departments[] = $dept;

            foreach ($deptData['children'] ?? [] as $sectionData) {
                $section = Department::create([
                    'name' => $sectionData['name'],
                    'code' => $sectionData['code'],
                    'description' => $sectionData['description'] ?? null,
                    'level' => Department::LEVEL_SECTION,
                    'parent_id' => $dept->id,
                    'organization_id' => $this->organization->id,
                    'is_active' => true,
                ]);
                $codeMap[$sectionData['code']] = $section->id;
                $this->departments[] = $section;
            }
        }
    }

    private function createUsers(): void
    {
        $usersData = [
            ['name' => 'د. عبدالرحمن بن محمد الفهد', 'email' => 'ceo@alnoor.sa', 'job_title' => 'الرئيس التنفيذي', 'role' => 'admin', 'dept' => 'SECTOR-MED'],
            ['name' => 'د. سلطان بن أحمد الزهراني', 'email' => 'cmo@alnoor.sa', 'job_title' => 'المدير الطبي', 'role' => 'admin', 'dept' => 'MED-DIR'],
            ['name' => 'أ. نواف بن فهد العتيبي', 'email' => 'coo@alnoor.sa', 'job_title' => 'مدير التشغيل', 'role' => 'admin', 'dept' => 'SECTOR-OPS'],
            ['name' => 'أ. هيا بنت سعود الحربي', 'email' => 'cfo@alnoor.sa', 'job_title' => 'المديرة المالية', 'role' => 'admin', 'dept' => 'FIN-DIR'],
            ['name' => 'م. فيصل بن سعود المطيري', 'email' => 'cio@alnoor.sa', 'job_title' => 'مدير تقنية المعلومات', 'role' => 'admin', 'dept' => 'IT-DIR'],
            ['name' => 'د. منى بنت عبدالله الشهري', 'email' => 'trans.director@alnoor.sa', 'job_title' => 'مديرة التخطيط والتحول', 'role' => 'admin', 'dept' => 'TRANS-DIR'],
            ['name' => 'م. أحمد بن سالم البقمي', 'email' => 'pmo.head@alnoor.sa', 'job_title' => 'مدير مكتب المشاريع', 'role' => 'admin', 'dept' => 'PMO'],
            ['name' => 'أ. سارة بنت فهد الغامدي', 'email' => 'pm.sara@alnoor.sa', 'job_title' => 'مديرة مشروع أولى', 'role' => 'project_manager', 'dept' => 'PMO'],
            ['name' => 'أ. محمد بن يوسف العمري', 'email' => 'pm.mohammed@alnoor.sa', 'job_title' => 'مدير مشروع أول', 'role' => 'project_manager', 'dept' => 'PMO'],
            ['name' => 'أ. لمى بنت إبراهيم الزهراني', 'email' => 'pm.lama@alnoor.sa', 'job_title' => 'مديرة مشروع', 'role' => 'project_manager', 'dept' => 'PMO'],
            ['name' => 'أ. ريم بنت ناصر القحطاني', 'email' => 'pmo.analyst1@alnoor.sa', 'job_title' => 'محللة مشاريع', 'role' => 'member', 'dept' => 'PMO-PLAN'],
            ['name' => 'أ. عبدالعزيز بن خالد القرني', 'email' => 'pmo.analyst2@alnoor.sa', 'job_title' => 'محلل مشاريع', 'role' => 'member', 'dept' => 'PMO-MON'],
            ['name' => 'أ. ياسر بن فهد البدر', 'email' => 'strat.head@alnoor.sa', 'job_title' => 'مدير التخطيط الاستراتيجي', 'role' => 'admin', 'dept' => 'STRAT'],
            ['name' => 'أ. أمل بنت سعد الحربي', 'email' => 'strat.plan@alnoor.sa', 'job_title' => 'أخصائية تخطيط', 'role' => 'member', 'dept' => 'STRAT-PLN'],
            ['name' => 'أ. سلطان بن ماجد العتيبي', 'email' => 'kpi.specialist@alnoor.sa', 'job_title' => 'أخصائي مؤشرات أداء', 'role' => 'member', 'dept' => 'STRAT-KPI'],
            ['name' => 'د. خالد بن عبدالعزيز السديري', 'email' => 'int.head@alnoor.sa', 'job_title' => 'استشاري طب باطنة - رئيس القسم', 'role' => 'admin', 'dept' => 'INT-MED'],
            ['name' => 'د. فهد بن محمد القحطاني', 'email' => 'card.head@alnoor.sa', 'job_title' => 'استشاري أمراض القلب', 'role' => 'admin', 'dept' => 'CARD'],
            ['name' => 'د. ريم بنت عبدالله الرشيد', 'email' => 'card.doc1@alnoor.sa', 'job_title' => 'استشارية قسطرة قلبية', 'role' => 'member', 'dept' => 'CARD'],
            ['name' => 'د. ماجد بن فهد الدوسري', 'email' => 'surg.head@alnoor.sa', 'job_title' => 'استشاري جراحة عامة', 'role' => 'admin', 'dept' => 'SURG'],
            ['name' => 'د. طارق بن ناصر العمري', 'email' => 'ortho.head@alnoor.sa', 'job_title' => 'استشاري جراحة عظام', 'role' => 'admin', 'dept' => 'ORTHO'],
            ['name' => 'د. سامي بن يوسف الفهد', 'email' => 'er.head@alnoor.sa', 'job_title' => 'استشاري طب طوارئ', 'role' => 'admin', 'dept' => 'EMRG'],
            ['name' => 'د. هند بنت فهد القرشي', 'email' => 'er.adult@alnoor.sa', 'job_title' => 'أخصائية طوارئ بالغين', 'role' => 'member', 'dept' => 'ER-ADULT'],
            ['name' => 'د. عبدالله بن ناصر السبيعي', 'email' => 'lab.head@alnoor.sa', 'job_title' => 'استشاري مختبرات طبية', 'role' => 'admin', 'dept' => 'LAB'],
            ['name' => 'أ. منى بنت سالم العنزي', 'email' => 'lab.tech@alnoor.sa', 'job_title' => 'فنية مختبر', 'role' => 'member', 'dept' => 'LAB-CLIN'],
            ['name' => 'أ. هند بنت سعود الفهد', 'email' => 'nur.svc@alnoor.sa', 'job_title' => 'مديرة خدمات التمريض', 'role' => 'admin', 'dept' => 'NUR-SVC'],
            ['name' => 'أ. نورة بنت فهد العتيبي', 'email' => 'nur.er@alnoor.sa', 'job_title' => 'مشرفة تمريض طوارئ', 'role' => 'member', 'dept' => 'NUR-ER'],
            ['name' => 'د. سارة بنت فهد الفهد', 'email' => 'qa@alnoor.sa', 'job_title' => 'مديرة الجودة', 'role' => 'admin', 'dept' => 'QA-MGT'],
            ['name' => 'أ. خالد بن فهد السبيعي', 'email' => 'qa.cbahi@alnoor.sa', 'job_title' => 'أخصائي اعتماد', 'role' => 'member', 'dept' => 'QA-CBAHI'],
            ['name' => 'د. هيا بنت فهد الحربي', 'email' => 'ps@alnoor.sa', 'job_title' => 'مديرة سلامة المرضى', 'role' => 'admin', 'dept' => 'PS-MGT'],
            ['name' => 'أ. منى بنت فهد العتيبي', 'email' => 'ps.inc@alnoor.sa', 'job_title' => 'أخصائية متابعة حوادث', 'role' => 'member', 'dept' => 'PS-INC'],
        ];

        $deptMap = [];
        foreach ($this->departments as $dept) {
            $deptMap[$dept->code] = $dept->id;
        }

        foreach ($usersData as $data) {
            $deptId = $deptMap[$data['dept']] ?? null;

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'job_title' => $data['job_title'],
                    'department_id' => $deptId,
                    'organization_id' => $this->organization->id,
                    'is_active' => true,
                ]
            );

            if ($deptId && $user->department_id !== $deptId) {
                $user->update(['department_id' => $deptId, 'organization_id' => $this->organization->id]);
            }

            if (! $user->hasRole($data['role'])) {
                $user->assignRole($data['role']);
            }

            $this->users[] = $user;
        }
    }

    private function assignDepartmentManagers(): void
    {
        $deptById = collect($this->departments)->keyBy('id');
        $usersByDept = [];
        foreach ($this->users as $user) {
            if (! $user->department_id) {
                continue;
            }
            $dept = $deptById[$user->department_id] ?? null;
            if ($dept) {
                $usersByDept[$dept->code][] = $user;
            }
        }

        foreach ($this->departments as $dept) {
            $candidates = $usersByDept[$dept->code] ?? [];
            $manager = collect($candidates)->first(fn ($u) => $u->hasRole('admin') || $u->hasRole('project_manager'))
                ?? collect($candidates)->first();

            if ($manager) {
                $dept->update(['manager_id' => $manager->id]);
            }
        }
    }

    private function seedIncidentTypes(): void
    {
        $incidentTypes = [
            ['name' => 'Safety Incident', 'name_ar' => 'حادثة سلامة', 'requires_reportable_type' => false],
            ['name' => 'Quality Incident', 'name_ar' => 'حادثة جودة', 'requires_reportable_type' => false],
            ['name' => 'Environmental Incident', 'name_ar' => 'حادثة بيئية', 'requires_reportable_type' => false],
            ['name' => 'Security Incident', 'name_ar' => 'حادثة أمنية', 'requires_reportable_type' => false],
            ['name' => 'Employee Incident', 'name_ar' => 'حادثة موظف', 'requires_reportable_type' => false],
            ['name' => 'Reportable Incident', 'name_ar' => 'حادثة إبلاغ إلزامي', 'requires_reportable_type' => true],
        ];

        foreach ($incidentTypes as $type) {
            IncidentType::updateOrCreate(
                ['name' => $type['name']],
                ['name_ar' => $type['name_ar'], 'is_active' => true, 'requires_reportable_type' => $type['requires_reportable_type']]
            );
        }

        $reportable = IncidentType::where('name', 'Reportable Incident')->firstOrFail();

        $reportableTypes = [
            ['name' => 'Medication Error', 'name_ar' => 'خطأ دوائي'],
            ['name' => 'Patient Fall', 'name_ar' => 'سقوط مريض'],
            ['name' => 'Wrong Procedure/Patient', 'name_ar' => 'إجراء/مريض خاطئ'],
            ['name' => 'Medical Device Failure', 'name_ar' => 'عطل في جهاز طبي'],
            ['name' => 'Healthcare Associated Infection', 'name_ar' => 'عدوى مرتبطة بالرعاية الصحية'],
            ['name' => 'Data Breach or Loss', 'name_ar' => 'اختراق أو فقدان بيانات'],
            ['name' => 'Threat or Assault', 'name_ar' => 'تهديد أو اعتداء'],
        ];

        foreach ($reportableTypes as $rt) {
            ReportableType::updateOrCreate(
                ['incident_type_id' => $reportable->id, 'name' => $rt['name']],
                ['name_ar' => $rt['name_ar']]
            );
        }
    }
}
