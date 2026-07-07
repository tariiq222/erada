<?php

/**
 * QA fixture seeder for the project/task completion e2e suite.
 *
 * Creates fresh, isolated fixtures on every run (unique names) so the e2e
 * specs are order-independent and re-runnable, then writes their IDs to
 * e2e/.fixtures.json for the Playwright specs to consume.
 *
 * Run (host CLI talks to the dev DB on 127.0.0.1:5432):
 *   DB_HOST=127.0.0.1 DB_PORT=5432 php artisan tinker \
 *     --execute="require base_path('scripts/qa/seed-completion-e2e.php');"
 *
 * Scope: test fixtures only. Does not touch production code or migrations.
 */

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;

$stamp = date('ymd-His');
$orgId = 1;
$deptId = 1;
$adminId = 1;
$today = date('Y-m-d');
$end = date('Y-m-d', strtotime('+30 days'));

$makeProject = function (string $type, string $label, string $status = 'in_progress') use ($stamp, $orgId, $deptId, $adminId, $today, $end): Project {
    $attrs = [
        'name' => "E2E {$label} {$stamp}",
        'description' => 'E2E completion fixture',
        'organization_id' => $orgId,
        'department_id' => $deptId,
        'created_by' => $adminId,
        'type' => $type,
        'status' => $status,
        'priority' => 'medium',
        'start_date' => $today,
        'end_date' => $end,
        'progress' => $status === 'completed' ? 100 : 40,
    ];

    if ($status === 'completed') {
        $attrs['lessons_learned'] = 'درس مستفاد تجريبي';
        $attrs['outcome_summary'] = 'نتيجة تجريبية';
        $attrs['achievement_status'] = 'achieved';
        $attrs['actual_end_date'] = $today;
    }

    if ($type === 'improvement') {
        // Methodology fields so an improvement project is internally consistent.
        $attrs['problem_statement'] = 'ارتفاع زمن المعالجة في العملية الحالية';
        $attrs['target_process'] = 'عملية المعالجة';
        $attrs['root_cause'] = 'خطوات يدوية متكررة';
        $attrs['expected_benefits'] = 'تقليل الزمن بنسبة 30%';
    } else {
        $attrs['business_case'] = 'مبرر تجريبي';
        $attrs['approval_criteria'] = 'اعتماد الراعي';
        $attrs['exit_criteria'] = 'قبول المخرجات';
    }

    return Project::create($attrs);
};

$makeTask = function (Project $project, string $label, ?int $parentId = null, string $status = 'in_progress') use ($stamp, $adminId, $end): Task {
    return Task::create([
        'title' => "E2E Task {$label} {$stamp}",
        'description' => 'E2E task completion fixture',
        'type' => 'project',
        'status' => $status,
        'priority' => 'medium',
        'due_date' => $end,
        'progress' => $status === 'completed' ? 100 : 30,
        'project_id' => $project->id,
        'parent_id' => $parentId,
        'assigned_to' => $adminId,
    ]);
};

// ── Project closure fixtures ────────────────────────────────────────────────
$pNewClosure = $makeProject('new', 'NewClosure');          // new completion happy path
$pImpClosure = $makeProject('improvement', 'ImpClosure');   // improvement happy path
$pNewValidation = $makeProject('new', 'NewValidation');     // empty-field validation
$pImpPercent = $makeProject('improvement', 'ImpPercent');   // achievement % boundary validation

// Closure-button visibility per status
$pOnHold = $makeProject('new', 'OnHold', 'on_hold');        // button visible
$pDraft = $makeProject('new', 'Draft', 'draft');            // button hidden
$pCompleted = $makeProject('new', 'Completed', 'completed'); // button hidden
$pCancelled = $makeProject('new', 'Cancelled', 'cancelled'); // button hidden

// ── Task completion fixtures ────────────────────────────────────────────────
$pImpForTask = $makeProject('improvement', 'ImpTaskHost');
$taskImprovement = $makeTask($pImpForTask, 'Improvement');  // PDCA lessons required

$pNewForTask = $makeProject('new', 'NewTaskHost');
$taskPlain = $makeTask($pNewForTask, 'Plain');              // direct completion, no modal

$pSubtaskHost = $makeProject('new', 'SubtaskHost');
$taskParent = $makeTask($pSubtaskHost, 'Parent');
$taskChild = $makeTask($pSubtaskHost, 'Child', $taskParent->id, 'in_progress'); // incomplete subtask

// Separate pair for the positive subtask path (complete child → then parent)
$taskParentPositive = $makeTask($pSubtaskHost, 'ParentPositive');
$taskChildPositive = $makeTask($pSubtaskHost, 'ChildPositive', $taskParentPositive->id, 'in_progress');

// improvement project so moving its task to in_review opens the comment-required modal
$pReviewHost = $makeProject('improvement', 'ReviewHost');
$taskForReview = $makeTask($pReviewHost, 'ForReview'); // in_review comment-required flow

// in_progress project with an open task → closure modal shows the non-blocking warning
$pWithOpenTask = $makeProject('new', 'WithOpenTask');
$makeTask($pWithOpenTask, 'StillOpen', null, 'in_progress');

$fixtures = [
    'generatedAt' => date('c'),
    'stamp' => $stamp,
    'projectNewForClosure' => $pNewClosure->id,
    'projectImpForClosure' => $pImpClosure->id,
    'projectNewForValidation' => $pNewValidation->id,
    'projectImpForPercent' => $pImpPercent->id,
    'projectOnHold' => $pOnHold->id,
    'projectDraft' => $pDraft->id,
    'projectCompleted' => $pCompleted->id,
    'projectCancelled' => $pCancelled->id,
    'taskImprovement' => $taskImprovement->id,
    'taskPlain' => $taskPlain->id,
    'taskParentWithSubtask' => $taskParent->id,
    'taskChild' => $taskChild->id,
    'taskParentPositive' => $taskParentPositive->id,
    'taskChildPositive' => $taskChildPositive->id,
    'taskForReview' => $taskForReview->id,
    'projectWithOpenTask' => $pWithOpenTask->id,
];

$path = base_path('e2e/.fixtures.json');
file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo 'FIXTURES_WRITTEN='.json_encode($fixtures)."\n";
