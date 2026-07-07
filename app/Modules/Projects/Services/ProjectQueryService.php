<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Http\Resources\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectQueryService
{
    public function __construct(
        protected UserProjectScope $projectScope = new UserProjectScope
    ) {}

    /**
     * الأعمدة المسموح بالترتيب عليها (حماية من SQL Injection)
     */
    protected array $allowedSortColumns = [
        'name', 'code', 'status', 'priority', 'progress',
        'start_date', 'end_date', 'budget', 'created_at', 'updated_at',
    ];

    /**
     * بناء الاستعلام الأساسي مع العلاقات
     */
    public function baseQuery(): Builder
    {
        return Project::with([
            'department',
            'program:id,code,name',
            // scopedRoles + user is eager-loaded so the list endpoint can resolve
            // the project manager without firing a fresh query per row (N+1).
            // getManagerAttribute() always re-queries members(), so we use this
            // collection to derive manager in ProjectResource when present.
            'scopedRoles.user',
        ])
            // Counter aggregates replace per-row model hydration for the
            // members/tasks/milestones/risks counts that the list cards render.
            ->withCount(['tasks', 'milestones', 'risks'])
            ->withCount(['members as members_count']);
    }

    /**
     * تطبيق فلتر الصلاحيات على الاستعلام.
     *
     * مفوَّض كلياً إلى UserProjectScope كي يبقى نطاق القائمة (index) ونطاق العنصر
     * (show عبر findOrFail) ونطاق لوحة المعلومات مصدراً واحداً للحقيقة: سلّم
     * الصلاحيات المسطّح (own < department < all) مع عزل المؤسسة أولاً.
     */
    public function applyPermissionFilter(Builder $query, User $user): Builder
    {
        return $this->projectScope->apply($query, $user);
    }

    /**
     * تقييد على المشاريع المرتبطة مباشرةً بالمستخدم (تاب "مشاريعي") — ملكية فقط،
     * لا يشمل الموقع الصاعد عمداً.
     *
     * نجمع معرفات المشاريع من خمس مصادر عبر استعلامات pluck رخيصة بدلاً من
     * OR'd whereHas، لأن الأخيرة تشغّل استعلاماً واحداً لكل صف ظاهري.
     */
    public function applyOwnershipScope(Builder $query, User $user): Builder
    {
        // The legacy `project_members` table was dropped on 2026-06-14 in favor of the
        // scoped-role engine; the `scoped_roles` shorthand here was a typo and never
        // matched anything. The unified source of truth is `model_has_scoped_roles`
        // with `scope_type = 'project'`. Other branches (created_by / stakeholders /
        // assigned tasks) remain as compensating signals for projects where the user
        // has no scoped role yet (creator, explicit stakeholder, assignee).
        $ownedIds = array_unique(array_merge(
            Project::where('created_by', $user->id)->pluck('id')->all(),
            DB::table('model_has_scoped_roles')
                ->where('user_id', $user->id)
                ->where('scope_type', ScopedRole::SCOPE_PROJECT)
                ->pluck('scope_id')->all(),
            DB::table('project_stakeholders')->where('user_id', $user->id)->pluck('project_id')->all(),
            DB::table('tasks')->where('assigned_to', $user->id)
                ->whereNotNull('project_id')->pluck('project_id')->all(),
        ));

        return $query->whereIn('projects.id', $ownedIds ?: [-1]);
    }

    /**
     * تطبيق فلاتر البحث والتصفية
     */
    public function applyFilters(Builder $query, Request $request): Builder
    {
        // البحث
        if ($request->has('search')) {
            $search = $request->search;
            // Postgres ilike is case-insensitive (project is Postgres-only per AGENTS.md).
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        // تصفية بالحالة
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // تصفية بالأولوية
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // تصفية بالمبادرة (Program)
        if ($request->has('program_id')) {
            $programId = $request->program_id;
            if ($programId === 'none') {
                $query->whereNull('program_id');
            } elseif (is_numeric($programId)) {
                $query->where('program_id', (int) $programId);
            }
            // تجاهل القيم غير الصالحة (مثل النصوص غير الرقمية)
        }

        // تصفية بنوع المشروع (development / improvement)
        if ($request->has('type') && in_array($request->type, ['development', 'improvement'])) {
            $query->where('type', $request->type);
        }

        return $query;
    }

    /**
     * تطبيق الترتيب
     */
    public function applySorting(Builder $query, Request $request): Builder
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');

        // التحقق من أن العمود مسموح به
        if (! in_array($sortBy, $this->allowedSortColumns)) {
            $sortBy = 'created_at';
        }

        // التحقق من اتجاه الترتيب
        $sortDir = in_array(strtolower($sortDir), ['asc', 'desc']) ? $sortDir : 'desc';

        return $query->orderBy($sortBy, $sortDir);
    }

    /**
     * جلب قائمة المشاريع مع التصفية والترقيم
     */
    public function getPaginatedList(Request $request, User $user): LengthAwarePaginator
    {
        $query = $this->baseQuery();
        $query = $this->applyPermissionFilter($query, $user);
        $query = $this->applyFilters($query, $request);

        if ($request->boolean('mine')) {
            $query = $this->applyOwnershipScope($query, $user);
        }

        $query = $this->applySorting($query, $request);

        $paginator = $query->paginate(min((int) $request->get('per_page', 15), 100));

        // Wrap items with ProjectResource so the manager field is serialized
        // correctly via the eager-loaded scopedRoles collection. The controller
        // just does response()->json($paginator) and otherwise the manager
        // accessor would not appear in the JSON (model has no $appends).
        $paginator->getCollection()->transform(
            fn (Project $project) => new ProjectResource($project)
        );

        return $paginator;
    }

    /**
     * جلب مشروع واحد مع كل العلاقات
     */
    public function getProjectWithRelations(string $id, User $user): Project
    {
        $query = Project::with([
            'department',
            'program:id,code,name',
            'creator',
            'members',
            'milestones.deliverables',
            'tasks' => fn ($q) => $q->whereNull('parent_id')
                ->withCount('subtasks')
                ->with(['assignee', 'milestone:id,name']),
            'stakeholders',
            'kpis',
            'risks' => fn ($q) => $q->orderBy('order', 'asc'),
        ]);

        $query = $this->applyPermissionFilter($query, $user);

        return $query->findOrFail($id);
    }

    /**
     * جلب إحصائيات المشروع
     */
    public function getProjectStats(string $id, User $user): Project
    {
        $query = Project::withCount([
            'tasks',
            'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            // Exclude cancelled tasks from overdue count.
            'tasks as overdue_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])
                ->where('due_date', '<', now()),
            'members as members_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)')),
            'milestones',
        ]);

        $query = $this->applyPermissionFilter($query, $user);

        return $query->findOrFail($id);
    }
}
