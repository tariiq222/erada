<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Http\Requests\DeleteDepartmentRequest;
use App\Modules\HR\Http\Requests\StoreDepartmentRequest;
use App\Modules\HR\Http\Requests\UpdateDepartmentRequest;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Support\ElementAbilities;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DepartmentController extends Controller
{
    use HasOrganizationScope;

    /**
     * Display a listing of departments.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // D-03: null-org non-super denial
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        // The canonical admin surface sends an explicit organization filter.
        // Super-admins may select any existing organization, but stale headers
        // remain ignored. Non-super users continue resolving only their active
        // organization and cannot widen tenancy through this query parameter.
        if ($user->isSuperAdmin()) {
            $validated = $request->validate([
                'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            ]);
            $resolvedOrg = isset($validated['organization_id'])
                ? (int) $validated['organization_id']
                : null;
        } else {
            $requestedOrg = (int) $request->header('X-Organization-Id') ?: null;
            $resolvedOrg = $user->resolveActiveOrganizationId($requestedOrg);
        }

        $query = Department::query()
            ->with(['parent:id,name', 'manager:id,name'])
            ->forOrganization($resolvedOrg);

        // Filter by status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by parent
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $departments = $query->withCount('users')
            ->orderBy('name')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        // إضافة level_name لكل قسم
        $departments->getCollection()->transform(function ($dept) {
            $dept->level_name = $dept->getLevelNameAttribute();

            return $dept;
        });

        return response()->json($departments);
    }

    /**
     * Get departments as a simple list (for dropdowns).
     *
     * This method is bound to two routes:
     *  - GET /api/hr/departments/list (auth:sanctum) — full list scoped to org
     *  - GET /api/public/departments (no auth) — public-safe subset for setup-account
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();

        // Public/unauthenticated branch: only active departments, no PII,
        // no manager_id, no organization_id, no is_active flag.
        if ($user === null) {
            $departments = Department::query()
                ->active()
                ->select('id', 'name', 'code', 'parent_id', 'level')
                ->orderBy('name')
                ->get()
                ->map(function ($dept) {
                    return [
                        'id' => $dept->id,
                        'name' => $dept->name,
                        'code' => $dept->code,
                        'parent_id' => $dept->parent_id,
                        'level' => $dept->level,
                        'level_name' => $dept->getLevelNameAttribute(),
                    ];
                })
                ->values();

            return response()->json($departments);
        }

        // D-03: null-org non-super denial
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'parent_id', 'level')
            ->orderBy('name')
            ->get()
            ->map(function ($dept) {
                $dept->level_name = $dept->getLevelNameAttribute();

                return $dept;
            });

        return response()->json($departments);
    }

    /**
     * Get departments as a tree structure.
     */
    public function tree(Request $request): JsonResponse
    {
        $user = $request->user();

        // D-03: null-org non-super denial
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        // Flat fetch: one query for all departments (with materialized path for
        // in-memory tree assembly), one query for manager lookup, one query for
        // user counts. Replaces the recursive allChildren eager load which ran
        // one extra query per depth level.
        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->with(['manager:id,name'])
            ->withCount('users')
            ->select('id', 'name', 'code', 'parent_id', 'level', 'manager_id', 'sort_order', 'path')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $forest = $this->buildForest($departments);

        return response()->json($forest);
    }

    /**
     * Assemble a forest of department trees from a flat collection using the
     * materialized `path` column for parent lookup (O(n) instead of O(n²) and
     * no recursive lazy-load).
     *
     * @param  Collection<int, Department>  $departments
     * @return array<int, array<string, mixed>>
     */
    private function buildForest($departments): array
    {
        // Index by id for O(1) child attachment.
        $byId = $departments->keyBy('id')->all();

        // Attach each non-root department under its parent. The materialized path
        // encodes the ancestor chain (path = '/1/4/7/'), so parent_id alone
        // would also work; we use it directly to avoid parsing the path string.
        //
        // ponytail: PHP 8.4 rejects `$parent->children_arr[] = $dept` as
        // "Indirect modification of overloaded property" — unknown Eloquent
        // attributes route through __get, which returns a value but yields no
        // reference to take an array offset on. SetAttribute() bypasses __get
        // and writes straight into the model's $attributes array; merging in
        // the existing value keeps previously-attached siblings.
        foreach ($departments as $dept) {
            if ($dept->parent_id === null) {
                continue;
            }
            $parent = $byId[$dept->parent_id] ?? null;
            if ($parent) {
                $existing = $parent->getAttribute('children_arr') ?? [];
                $existing[] = $dept;
                $parent->setAttribute('children_arr', $existing);
            }
        }

        // Roots are departments with no parent; build the forest top-down.
        $roots = $departments->whereNull('parent_id');

        return $roots
            ->map(fn ($dept) => $this->transformDepartmentTree($dept))
            ->values()
            ->toArray();
    }

    /**
     * Transform a collection of departments to tree format.
     */
    private function transformTreeCollection($departments): array
    {
        return $departments->map(fn ($dept) => $this->transformDepartmentTree($dept))->toArray();
    }

    /**
     * Transform a single department with its children recursively.
     */
    private function transformDepartmentTree($dept): array
    {
        $result = [
            'id' => $dept->id,
            'name' => $dept->name,
            'code' => $dept->code,
            'parent_id' => $dept->parent_id,
            'level' => $dept->level,
            'level_name' => $dept->getLevelNameAttribute(),
            'manager' => $dept->manager ? [
                'id' => $dept->manager->id,
                'name' => $dept->manager->name,
            ] : null,
            'employees_count' => $dept->users_count ?? 0,
            'children' => [],
        ];

        if (! empty($dept->children_arr)) {
            $result['children'] = collect($dept->children_arr)
                ->map(fn ($child) => $this->transformDepartmentTree($child))
                ->values()
                ->toArray();
        }

        return $result;
    }

    /**
     * Get departments hierarchy for project form.
     * Returns all departments with their ancestors for auto-selection.
     */
    public function hierarchy(Request $request): JsonResponse
    {
        $user = $request->user();

        // D-03: null-org non-super denial
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'parent_id', 'level')
            ->orderBy('level')
            ->orderBy('name')
            ->get()
            ->map(function ($dept) {
                return [
                    'id' => $dept->id,
                    'name' => $dept->name,
                    'code' => $dept->code,
                    'parent_id' => $dept->parent_id,
                    'level' => $dept->level,
                    'level_name' => $dept->getLevelNameAttribute(),
                ];
            });

        // فصل حسب المستويات
        $result = [
            'all' => $departments,
            // الإدارات (مستوى 1-3)
            'departments' => $departments->filter(fn ($d) => $d['level'] <= 3)->values(),
            // الأقسام (مستوى 4)
            'sections' => $departments->filter(fn ($d) => $d['level'] == 4)->values(),
            // الوحدات (مستوى 5-6)
            'units' => $departments->filter(fn ($d) => $d['level'] >= 5)->values(),
        ];

        return response()->json($result);
    }

    /**
     * Get allowed levels for a parent department.
     */
    public function allowedLevels(Request $request): JsonResponse
    {
        $parentId = $request->get('parent_id');

        // Normalize empty/absent values to null. Note: the global
        // ConvertEmptyStringsToNull middleware already turns `?parent_id=` into
        // null, so an explicit null must be preserved (not cast to 0).
        if ($parentId === null || $parentId === '' || $parentId === 'null') {
            $parentId = null;
        } else {
            $parentId = (int) $parentId;
        }

        $allowedLevels = Department::getAllowedChildLevels($parentId);

        return response()->json([
            'levels' => $allowedLevels,
            'all_levels' => Department::getAllLevels(),
        ]);
    }

    /**
     * Store a newly created department.
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // D-06: parent must be same-org
        if (! empty($validated['parent_id'])) {
            $parent = Department::find($validated['parent_id']);
            if ($parent && ! $this->sharesOrganization($user, $parent->organization_id)) {
                return response()->json(['message' => 'القسم الأب يجب أن ينتمي لنفس المؤسسة'], 403);
            }
        }

        // التحقق من صحة التسلسل الهرمي
        $parentId = $validated['parent_id'] ?? null;
        $level = $validated['level'];

        if (! Department::isValidHierarchy($parentId, $level)) {
            return response()->json([
                'message' => Department::getHierarchyErrorMessage($parentId, $level),
            ], 422);
        }

        // D-04: stamp organization_id
        $validated['organization_id'] = $user->isSuperAdmin()
            ? ($validated['organization_id'] ?? $user->organization_id)
            : $user->organization_id;

        $department = Department::create($validated);

        // Capacity-role policy for the new department is configured separately
        // by the SPA via PUT /hr/departments/{department}/capacity-roles after
        // creation (DepartmentCapacityRoleController), which then syncs members.

        // تحميل البيانات المرتبطة فقط إذا كانت موجودة
        $department->load(['parent:id,name', 'manager:id,name']);
        $department->level_name = $department->getLevelNameAttribute();

        return response()->json([
            'message' => 'تم إنشاء القسم بنجاح',
            'department' => $department,
        ], 201);
    }

    /**
     * Display the specified department.
     */
    public function show(Request $request, Department $department): JsonResponse
    {
        if (! $this->sharesOrganization($request->user(), $department->organization_id)) {
            return response()->json(['message' => 'غير مصرح بالوصول إلى هذا القسم'], 403);
        }

        $payload = $department->load([
            'parent:id,name',
            'manager:id,name',
            'users:id,department_id,name',
            'children:id,parent_id,name,code',
        ])->loadCount('users')->toArray();

        // Per-record abilities — resolved through AccessDecision so the
        // frontend never re-derives scope-chain logic.
        $payload['abilities'] = ElementAbilities::resolve(
            $request->user(),
            $department,
            [
                'view' => Capability::DEPARTMENTS_VIEW,
                'edit' => Capability::DEPARTMENTS_EDIT,
                'delete' => Capability::DEPARTMENTS_DELETE,
                'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
                'assign_roles' => Capability::DEPARTMENTS_ASSIGN_ROLES,
            ]
        );

        return response()->json($payload);
    }

    /**
     * Update the specified department.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Prevent setting self as parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $department->id) {
            return response()->json([
                'message' => 'لا يمكن تعيين القسم كأب لنفسه',
            ], 422);
        }

        // D-06: parent must be same-org
        if (! empty($validated['parent_id'])) {
            $parent = Department::find($validated['parent_id']);
            if ($parent && ! $this->sharesOrganization($user, $parent->organization_id)) {
                return response()->json(['message' => 'القسم الأب يجب أن ينتمي لنفس المؤسسة'], 403);
            }
        }

        // التحقق من صحة التسلسل الهرمي فقط إذا تغير القسم الأب أو المستوى
        $parentId = $validated['parent_id'] ?? null;
        $level = $validated['level'];

        $parentChanged = $parentId != $department->parent_id;
        $levelChanged = $level != $department->level;

        // تحقق من التسلسل الهرمي فقط إذا تغير الأب أو المستوى
        if (($parentChanged || $levelChanged) && ! Department::isValidHierarchy($parentId, $level)) {
            return response()->json([
                'message' => Department::getHierarchyErrorMessage($parentId, $level),
            ], 422);
        }

        // Do not allow changing organization_id
        unset($validated['organization_id']);

        $department->update($validated);
        $department->level_name = $department->getLevelNameAttribute();

        return response()->json([
            'message' => 'تم تحديث القسم بنجاح',
            'department' => $department->load(['parent:id,name', 'manager:id,name']),
        ]);
    }

    /**
     * Remove the specified department.
     */
    public function destroy(DeleteDepartmentRequest $request, Department $department): JsonResponse
    {
        // Check if department has users
        if ($department->users()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف قسم يحتوي على موظفين',
            ], 422);
        }

        // Check if department has children
        if ($department->children()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف قسم يحتوي على أقسام فرعية',
            ], 422);
        }

        $department->delete();

        return response()->json([
            'message' => 'تم حذف القسم بنجاح',
        ]);
    }
}
