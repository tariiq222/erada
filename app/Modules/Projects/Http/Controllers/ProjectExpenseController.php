<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Http\Requests\DeleteProjectExpenseRequest;
use App\Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectExpenseRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectExpenseController extends Controller
{
    /**
     * Only the true organization-administrator tier may override the finalized
     * lock on an expense. A project manager who merely carries projects.edit (and
     * therefore the broad `manage_organization` capability granted by their role)
     * is NOT permitted to mutate a finalized expense — that is a deliberate
     * financial-integrity control.
     *
     * The admin tier is identified by a DIRECT `manage_organization` grant (an
     * explicit administrator assignment) or super_admin, not by the capability
     * inherited through the functional `admin` role that managers also hold.
     */
    private function canModifyFinalizedExpense(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Boundary preserved from legacy `hasPermissionTo('manage_organization')`:
        // super_admin OR an explicit SETTINGS_MANAGE grant. The Spatie `admin`
        // role is intentionally excluded — it grants admin-tier through the engine's
        // is_admin_role short-circuit, but the legacy semantic required a DIRECT
        // capability grant, not role inheritance. User::isAdmin() already routes
        // through AccessDecision (post Core pre-cleanup 4674569b) and includes
        // super_admin, so this composite mirrors the old boundary.
        return $user->isSuperAdmin()
            || ($user->isAdmin() && AccessDecision::can($user, Capability::SETTINGS_MANAGE));
    }

    /**
     * عرض قائمة مصروفات المشروع
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // جلب فقط الحقول المطلوبة للقائمة
        $query = $project->expenses()
            ->select(['id', 'title', 'description', 'amount', 'category', 'expense_date', 'reference_number', 'attachment_path', 'task_id', 'created_by', 'created_at'])
            ->with([
                'creator:id,name',
                'task:id,title',
            ]);

        // تصفية حسب التصنيف
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // تصفية حسب التاريخ
        if ($request->filled('from_date')) {
            $query->whereDate('expense_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('expense_date', '<=', $request->to_date);
        }

        // ترقيم الصفحات بدلاً من get() كل النتائج — يحمي من unbounded growth.
        $paginator = $query->orderBy('expense_date', 'desc')
            ->paginate(min($request->integer('per_page', 25), 100));

        // إحصائيات مجمّعة على مستوى قاعدة البيانات (SQL aggregates) بدلاً من
        // collection hydration في الذاكرة — لا تكلفة على حجم البيانات.
        $stats = [
            'total_expenses' => (float) DB::table('project_expenses')
                ->where('project_id', $project->id)
                ->sum('amount'),
            'budget' => $project->budget ?? 0,
            'spent_amount' => $project->spent_amount ?? 0,
            'remaining' => ($project->budget ?? 0) - ($project->spent_amount ?? 0),
            'percentage_used' => $project->budget > 0
                ? round(($project->spent_amount / $project->budget) * 100, 1)
                : 0,
            'by_category' => DB::table('project_expenses')
                ->where('project_id', $project->id)
                ->selectRaw('category, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total')
                ->groupBy('category')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    $row->category => [
                        'count' => (int) $row->c,
                        'total' => (float) $row->total,
                    ],
                ]),
        ];

        return response()->json([
            'expenses' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'stats' => $stats,
            'categories' => ProjectExpense::CATEGORIES,
        ]);
    }

    /**
     * إضافة مصروف جديد
     */
    public function store(StoreProjectExpenseRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        if (! empty($validated['task_id'])) {
            $taskProjectId = Task::where('id', $validated['task_id'])->value('project_id');
            if ((int) $taskProjectId !== (int) $project->id) {
                return response()->json([
                    'message' => 'المهمة المحددة لا تتبع هذا المشروع',
                    'errors' => [
                        'task_id' => ['المهمة المحددة لا تنتمي إلى هذا المشروع'],
                    ],
                ], 422);
            }
        }

        $validated['project_id'] = $project->id;
        $validated['created_by'] = $request->user()->id;

        // رفع المرفق إن وجد
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store(
                "projects/{$project->id}/expenses",
                'local'
            );
            $validated['attachment_path'] = $path;
        }

        $expense = ProjectExpense::create($validated);

        // تسجيل النشاط
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'user_id' => $request->user()->id,
            'action' => 'expense_added',
            'description' => "تم إضافة مصروف: {$expense->title} بمبلغ ".number_format($expense->amount, 2),
            // organization_id من الـ Project نفسه (لا من request user).
            'organization_id' => app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Project::class, $project->id),
            // 'changes' ليس في $fillable، استبدل بـ new_values.
            'new_values' => [
                'expense_id' => $expense->id,
                'amount' => $expense->amount,
                'category' => $expense->category,
            ],
        ]);

        // تحقق من تجاوز 80% من الميزانية
        $warning = null;
        if ($project->budget > 0) {
            $percentage = ($project->fresh()->spent_amount / $project->budget) * 100;
            if ($percentage >= 100) {
                $warning = 'تم تجاوز الميزانية المحددة للمشروع!';
            } elseif ($percentage >= 80) {
                $warning = 'تم استهلاك أكثر من 80% من ميزانية المشروع';
            }
        }

        return response()->json([
            'message' => 'تم إضافة المصروف بنجاح',
            'expense' => $expense->load(['creator', 'task:id,title']),
            'warning' => $warning,
            'new_spent_amount' => $project->fresh()->spent_amount,
        ], 201);
    }

    /**
     * عرض مصروف محدد
     */
    public function show(Project $project, ProjectExpense $expense): JsonResponse
    {
        $this->authorize('view', $project);

        // التأكد من أن المصروف تابع للمشروع
        if ($expense->project_id !== $project->id) {
            return response()->json(['message' => 'المصروف غير موجود'], 404);
        }

        return response()->json([
            'expense' => $expense->load(['creator', 'task:id,title']),
        ]);
    }

    /**
     * تحديث مصروف
     */
    public function update(UpdateProjectExpenseRequest $request, Project $project, ProjectExpense $expense): JsonResponse
    {
        // Authz against project's `update` ability already enforced by the
        // FormRequest. The two invariants below are cross-record checks, not
        // pure input validation, so they stay here.

        // التأكد من أن المصروف تابع للمشروع
        if ($expense->project_id !== $project->id) {
            return response()->json(['message' => 'المصروف غير موجود'], 404);
        }

        if ($expense->is_finalized && ! $this->canModifyFinalizedExpense($request->user())) {
            return response()->json([
                'message' => 'لا يمكن تعديل مصروف مُقفل إلا من قبل الإدارة',
            ], 403);
        }

        $validated = $request->validated();

        if (array_key_exists('task_id', $validated) && ! empty($validated['task_id'])) {
            $taskProjectId = Task::where('id', $validated['task_id'])->value('project_id');
            if ((int) $taskProjectId !== (int) $project->id) {
                return response()->json([
                    'message' => 'المهمة المحددة لا تتبع هذا المشروع',
                    'errors' => [
                        'task_id' => ['المهمة المحددة لا تنتمي إلى هذا المشروع'],
                    ],
                ], 422);
            }
        }

        $oldAmount = $expense->amount;

        // رفع المرفق الجديد إن وجد
        if ($request->hasFile('attachment')) {
            // حذف المرفق القديم
            if ($expense->attachment_path) {
                Storage::disk('local')->delete($expense->attachment_path);
            }
            $path = $request->file('attachment')->store(
                "projects/{$project->id}/expenses",
                'local'
            );
            $validated['attachment_path'] = $path;
        }

        $expense->update($validated);

        // تسجيل النشاط
        if ($oldAmount != $expense->amount) {
            ActivityLog::create([
                'loggable_type' => Project::class,
                'loggable_id' => $project->id,
                'user_id' => $request->user()->id,
                'action' => 'expense_updated',
                'description' => "تم تعديل المصروف: {$expense->title}",
                'organization_id' => app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                    ->resolveForLoggable(Project::class, $project->id),
                // 'changes' ليس في $fillable، استبدل بـ old_values + new_values.
                'old_values' => [
                    'expense_id' => $expense->id,
                    'amount' => $oldAmount,
                ],
                'new_values' => [
                    'expense_id' => $expense->id,
                    'amount' => $expense->amount,
                ],
            ]);
        }

        return response()->json([
            'message' => 'تم تحديث المصروف بنجاح',
            'expense' => $expense->fresh()->load(['creator', 'task:id,title']),
            'new_spent_amount' => $project->fresh()->spent_amount,
        ]);
    }

    /**
     * حذف مصروف
     */
    public function destroy(DeleteProjectExpenseRequest $request, Project $project, ProjectExpense $expense): JsonResponse
    {
        // Authz against project's `update` ability already enforced by the
        // FormRequest. Cross-record invariants (expense-belongs-to-project and
        // finalized-expense lock) stay here.

        // التأكد من أن المصروف تابع للمشروع
        if ($expense->project_id !== $project->id) {
            return response()->json(['message' => 'المصروف غير موجود'], 404);
        }

        if ($expense->is_finalized && ! $this->canModifyFinalizedExpense($request->user())) {
            return response()->json([
                'message' => 'لا يمكن حذف مصروف مُقفل إلا من قبل الإدارة',
            ], 403);
        }

        $expenseTitle = $expense->title;
        $expenseAmount = $expense->amount;

        // حذف المرفق
        if ($expense->attachment_path) {
            Storage::disk('local')->delete($expense->attachment_path);
        }

        $expense->delete();

        // تسجيل النشاط
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'user_id' => $request->user()->id,
            'action' => 'expense_deleted',
            'description' => "تم حذف المصروف: {$expenseTitle} بمبلغ ".number_format($expenseAmount, 2),
            'organization_id' => app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Project::class, $project->id),
            // 'changes' ليس في $fillable، استبدل بـ old_values.
            'old_values' => [
                'expense_id' => $expense->id,
                'amount' => $expenseAmount,
            ],
        ]);

        return response()->json([
            'message' => 'تم حذف المصروف بنجاح',
            'new_spent_amount' => $project->fresh()->spent_amount,
        ]);
    }

    /**
     * تنزيل/عرض مرفق المصروف عبر مسار مصادَق (disk خاص).
     *
     * يُعيد فحص سياسة العرض على المشروع ويتأكد أن المصروف تابع له، فلا يتسرّب
     * الإيصال المالي عبر رابط /storage عام كما كان سابقاً.
     */
    public function downloadAttachment(Project $project, ProjectExpense $expense)
    {
        $this->authorize('view', $project);

        if ($expense->project_id !== $project->id) {
            abort(404, 'المصروف غير موجود');
        }

        if (! $expense->attachment_path || ! Storage::disk('local')->exists($expense->attachment_path)) {
            abort(404, 'لا يوجد مرفق');
        }

        // عرض داخل المتصفح (inline) للصور وPDF
        return Storage::disk('local')->response($expense->attachment_path);
    }

    /**
     * ملخص المصروفات حسب التصنيف
     */
    public function summary(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // 3 استعلامات SQL مجمّعة بدلاً من hydration لكامل الجدول.
        $byCategoryRows = DB::table('project_expenses')
            ->where('project_id', $project->id)
            ->selectRaw('category, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $byCategory = collect(ProjectExpense::CATEGORIES)->mapWithKeys(function ($label, $key) use ($byCategoryRows) {
            $row = $byCategoryRows->get($key);

            return [$key => [
                'label' => $label,
                'count' => $row ? (int) $row->c : 0,
                'total' => $row ? (float) $row->total : 0.0,
            ]];
        });

        $monthly = DB::table('project_expenses')
            ->where('project_id', $project->id)
            ->selectRaw("to_char(expense_date, 'YYYY-MM') AS month, COALESCE(SUM(amount),0) AS total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $totalCount = DB::table('project_expenses')
            ->where('project_id', $project->id)
            ->count();

        $summary = [
            'budget' => $project->budget ?? 0,
            'spent_amount' => $project->spent_amount ?? 0,
            'remaining' => ($project->budget ?? 0) - ($project->spent_amount ?? 0),
            'percentage_used' => $project->budget > 0
                ? round(($project->spent_amount / $project->budget) * 100, 1)
                : 0,
            'by_category' => $byCategory,
            'monthly' => $monthly,
            'total_expenses_count' => $totalCount,
        ];

        return response()->json($summary);
    }
}
