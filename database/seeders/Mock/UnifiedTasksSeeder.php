<?php

namespace Database\Seeders\Mock;

use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Seeder;

class UnifiedTasksSeeder extends Seeder
{
    /** @var Task[] */
    public array $tasks = [];

    public function run(array $users, array $departments): void
    {
        $this->createPersonalTasks($users);
        $this->createDepartmentTasks($users, $departments);
        $this->createRecurringTasks($users);
        $this->createSubtasks($users);
    }

    private function createPersonalTasks(array $users): void
    {
        $titles = [
            'تجهيز عرض الإدارة العليا',
            'مراجعة البريد الإلكتروني اليومي',
            'تحديث الملاحظات الشخصية',
            'التخطيط للأسبوع القادم',
            'قراءة مقال تقني',
            'متابعة التطوير المهني',
        ];

        foreach ($titles as $title) {
            $owner = $users[array_rand($users)];
            $status = $this->randomStatus();
            $this->tasks[] = Task::create([
                'type' => TaskType::PERSONAL->value,
                'title' => $title,
                'description' => 'تفاصيل '.str_replace('مهمة', 'المهمة', $title),
                'status' => $status->value,
                'priority' => [TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH][array_rand([0, 1, 2])]->value,
                'progress' => $status === TaskStatus::COMPLETED ? 100 : rand(0, 80),
                'start_date' => now()->subDays(rand(1, 14)),
                'due_date' => now()->addDays(rand(1, 14)),
                'completed_date' => $status === TaskStatus::COMPLETED ? now()->subDays(rand(0, 3)) : null,
                'estimated_hours' => rand(1, 8),
                'actual_hours' => $status === TaskStatus::COMPLETED ? rand(1, 10) : rand(0, 4),
                'is_private' => (bool) rand(0, 1),
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
                'assigned_to' => $owner->id,
            ]);
        }
    }

    private function createDepartmentTasks(array $users, array $departments): void
    {
        $titles = [
            'تجهيز تقرير شهري للإدارة',
            'مراجعة طلبات الموارد البشرية',
            'تحديث وثيقة السياسات',
            'مراجعة الفواتير',
            'تنسيق مع الموردين',
        ];

        foreach ($titles as $title) {
            $dept = $departments[array_rand($departments)];
            $owner = $users[array_rand($users)];
            $assignee = $users[array_rand($users)];
            $status = $this->randomStatus();
            $this->tasks[] = Task::create([
                'type' => TaskType::DEPARTMENT->value,
                'title' => $title,
                'description' => 'تفاصيل مهمة قسم '.$dept->name,
                'status' => $status->value,
                'priority' => [TaskPriority::MEDIUM, TaskPriority::HIGH, TaskPriority::CRITICAL][array_rand([0, 1, 2])]->value,
                'progress' => $status === TaskStatus::COMPLETED ? 100 : rand(10, 70),
                'start_date' => now()->subDays(rand(2, 14)),
                'due_date' => now()->addDays(rand(7, 30)),
                'completed_date' => $status === TaskStatus::COMPLETED ? now()->subDays(rand(0, 5)) : null,
                'estimated_hours' => rand(2, 16),
                'actual_hours' => $status === TaskStatus::COMPLETED ? rand(2, 18) : rand(0, 6),
                'department_id' => $dept->id,
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
                'assigned_to' => $assignee->id,
            ]);
        }
    }

    private function createRecurringTasks(array $users): void
    {
        $templates = [
            ['title' => 'حضور اجتماع الفريق الأسبوعي', 'rule' => 'FREQ=WEEKLY;BYDAY=MO', 'interval' => 'weekly'],
            ['title' => 'تقديم تقرير التقدم الأسبوعي', 'rule' => 'FREQ=WEEKLY;BYDAY=FR', 'interval' => 'weekly'],
            ['title' => 'مراجعة الفواتير اليومية', 'rule' => 'FREQ=DAILY', 'interval' => 'daily'],
            ['title' => 'تقرير المبيعات الشهري', 'rule' => 'FREQ=MONTHLY;BYMONTHDAY=1', 'interval' => 'monthly'],
            ['title' => 'فحص أمني دوري', 'rule' => 'FREQ=WEEKLY;INTERVAL=2', 'interval' => 'bi-weekly'],
            ['title' => 'تحديث سياسة الوصول', 'rule' => 'FREQ=MONTHLY;BYMONTHDAY=15', 'interval' => 'monthly'],
        ];

        foreach ($templates as $template) {
            $owner = $users[array_rand($users)];
            $status = $this->randomStatus();
            $this->tasks[] = Task::create([
                'type' => TaskType::RECURRING->value,
                'title' => $template['title'],
                'description' => 'مهمة متكررة '.$template['interval'],
                'status' => $status->value,
                'priority' => TaskPriority::MEDIUM->value,
                'progress' => $status === TaskStatus::COMPLETED ? 100 : rand(20, 70),
                'start_date' => now()->subDays(rand(7, 30)),
                'due_date' => now()->addDays(rand(1, 7)),
                'completed_date' => $status === TaskStatus::COMPLETED ? now()->subDays(rand(0, 2)) : null,
                'estimated_hours' => rand(1, 4),
                'actual_hours' => rand(0, 3),
                'recurrence_rule' => $template['rule'],
                'next_occurrence' => now()->addDays(rand(1, 7)),
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
                'assigned_to' => $owner->id,
            ]);
        }
    }

    private function createSubtasks(array $users): void
    {
        $parents = array_filter($this->tasks, fn ($t) => $t->type === TaskType::DEPARTMENT->value || $t->type === TaskType::PERSONAL->value);
        if (empty($parents)) {
            return;
        }
        $parent = $parents[array_rand($parents)];

        $subTitles = ['تحضير المسودة الأولى', 'مراجعة الزميل', 'تجميع الملاحظات', 'اعتماد نهائي'];
        foreach ($subTitles as $subTitle) {
            $this->tasks[] = Task::create([
                'type' => $parent->type,
                'parent_id' => $parent->id,
                'title' => $subTitle.' — '.$parent->title,
                'description' => 'مهمة فرعية',
                'status' => [TaskStatus::TODO, TaskStatus::IN_PROGRESS, TaskStatus::COMPLETED][array_rand([0, 1, 2])]->value,
                'priority' => TaskPriority::MEDIUM->value,
                'progress' => rand(0, 100),
                'start_date' => $parent->start_date,
                'due_date' => $parent->due_date,
                'department_id' => $parent->department_id,
                'owner_id' => $parent->owner_id,
                'created_by' => $parent->owner_id,
                'assigned_to' => $parent->assigned_to,
            ]);
        }
    }

    private function randomStatus(): TaskStatus
    {
        return [TaskStatus::TODO, TaskStatus::IN_PROGRESS, TaskStatus::IN_REVIEW, TaskStatus::COMPLETED, TaskStatus::ON_HOLD][array_rand([0, 1, 2, 3, 4])];
    }
}
