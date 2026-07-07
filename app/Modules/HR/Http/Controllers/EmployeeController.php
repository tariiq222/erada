<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Http\Requests\DeleteEmployeeRequest;
use App\Modules\HR\Http\Requests\EmployeeStatisticsRequest;
use App\Modules\HR\Http\Requests\ListEmployeesRequest;
use App\Modules\HR\Http\Requests\StoreEmployeeProfileRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeProfileRequest;
use App\Modules\HR\Http\Requests\ViewEmployeeRequest;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Scopes\UserEmployeeScope;
use App\Modules\HR\Support\EmployeeOrgGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    /**
     * Employees are user accounts enriched with a 1×1 HR profile.
     */
    public function index(ListEmployeesRequest $request): JsonResponse
    {
        // Authz (HR_VIEW + org-isolation floor) owned by ListEmployeesRequest.
        $user = $request->user();
        $scope = app(UserEmployeeScope::class);

        $query = User::query()
            ->with(['department:id,name,manager_id', 'department.manager:id,name', 'employeeProfile']);

        // Phase 2: org-isolation via the unified scope (super_admin no-op,
        // null-org fail-closed, same-org filter).
        $scope->applyToUsers($query, $user);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('employeeProfile', fn ($p) => $p->where('employee_no', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->whereHas('employeeProfile', fn ($p) => $p->where('employment_status', $request->string('status')));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        $employees = $query->orderBy('name')->paginate(min($request->integer('per_page', 15), 100));

        foreach ($employees->getCollection() as $employeeItem) {
            $this->exposeDepartmentManager($employeeItem);
            $this->gateSensitiveProfile($user, $employeeItem);
        }

        return response()->json($employees);
    }

    public function show(ViewEmployeeRequest $request, User $employee): JsonResponse
    {
        // Authz + cross-org floor owned by ViewEmployeeRequest.
        $employee->load(['department:id,name,manager_id', 'department.manager:id,name', 'employeeProfile']);
        $this->exposeDepartmentManager($employee);
        $this->gateSensitiveProfile($request->user(), $employee);

        return response()->json($employee);
    }

    public function store(StoreEmployeeProfileRequest $request): JsonResponse
    {
        // Authz + payload cross-org checks owned by StoreEmployeeProfileRequest
        // (HR_MANAGE + same-org on user_id + same-org on dept_id). Controller
        // re-asserts same-org via the Guard as belt-and-braces.
        $actor = $request->user();
        $guard = app(EmployeeOrgGuard::class);

        $data = $request->validated();
        $userId = $data['user_id'] ?? null;
        $employee = $userId ? User::find($userId) : null;

        if (! $employee) {
            return response()->json(['message' => 'المستخدم غير موجود'], 404);
        }

        $guard->abortUnlessSameOrganization($actor, $guard->employeeOrgId($employee));

        unset($data['manager_id'], $data['user_id']);

        $deptId = $data['dept_id'] ?? null;
        unset($data['dept_id']);

        $personal = $data['personal_info'] ?? null;
        $certificates = $data['certificates'] ?? [];
        unset($data['personal_info'], $data['certificates']);

        if ($deptId !== null) {
            $employee->department_id = $deptId;
        }

        $profile = DB::transaction(function () use ($employee, $data, $personal, $certificates) {
            if ($employee->isDirty('department_id')) {
                $employee->save();
            }

            $profile = EmployeeProfile::create($data + ['user_id' => $employee->id]);

            if ($personal !== null) {
                $profile->personalInfo()->create($personal);
            }

            foreach ($certificates as $cert) {
                EmployeeCertificate::create([
                    'employee_profile_id' => $profile->id,
                    'type' => $cert['type'],
                    'title' => $cert['title'] ?? null,
                    'issued_at' => $cert['issued_at'] ?? null,
                    'expires_at' => $cert['expires_at'] ?? null,
                    'notes' => $cert['notes'] ?? null,
                ]);
            }

            return $profile;
        });

        $employee->load('department.manager');
        $profile->load(['personalInfo', 'certificates']);
        $profile->loadCount('certificates');
        $profile->setRelation('manager', $employee->department?->manager);

        return response()->json($profile, 201);
    }

    /**
     * Create or update the HR profile attached to a user.
     */
    public function update(UpdateEmployeeProfileRequest $request, User $employee): JsonResponse
    {
        // Authz + cross-org + null-org checks owned by UpdateEmployeeProfileRequest.
        // Belt-and-braces re-assertion via the Guard keeps the controller safe even
        // if a future route consumes the FormRequest directly without the controller.
        $user = $request->user();
        $guard = app(EmployeeOrgGuard::class);

        $guard->abortUnlessSameOrganization($user, $guard->employeeOrgId($employee));

        $data = $request->validated();
        unset($data['manager_id']);

        $deptId = $data['dept_id'] ?? null;
        unset($data['dept_id']);

        if ($deptId !== null) {
            $employee->department_id = $deptId;
        }

        if ($employee->isDirty('department_id')) {
            $employee->save();
        }

        $profile = EmployeeProfile::updateOrCreate(
            ['user_id' => $employee->id],
            $data,
        );

        $employee->load('department.manager');
        $profile->setRelation('manager', $employee->department?->manager);

        return response()->json($profile);
    }

    public function statistics(EmployeeStatisticsRequest $request): JsonResponse
    {
        // Authz (HR_VIEW + org-isolation floor) owned by EmployeeStatisticsRequest.
        $user = $request->user();
        $scope = app(UserEmployeeScope::class);

        $base = EmployeeProfile::query();

        // Phase 2: org-isolation via the unified scope (same logic as index,
        // but at the profile level — filters by user.organization_id via relation).
        $scope->applyToProfiles($base, $user);

        return response()->json([
            'total' => (clone $base)->count(),
            'by_status' => (clone $base)->selectRaw('employment_status, count(*) as count')
                ->groupBy('employment_status')->pluck('count', 'employment_status'),
            'by_type' => (clone $base)->selectRaw('employment_type, count(*) as count')
                ->groupBy('employment_type')->pluck('count', 'employment_type'),
        ]);
    }

    public function destroy(DeleteEmployeeRequest $request, User $employee): JsonResponse
    {
        // Authz + cross-org + null-org checks owned by DeleteEmployeeRequest.
        $profile = $employee->employeeProfile;

        if ($profile) {
            $profile->delete();
        }

        return response()->json(null, 204);
    }

    private function exposeDepartmentManager(User $employee): void
    {
        if ($employee->relationLoaded('department') && $employee->department) {
            $employee->setRelation('manager', $employee->department->manager);
        }
    }

    /**
     * Hide biometric / social-insurance profile fields (H-05) from actors that
     * can only view HR data. They stay visible to org admins, super admins,
     * HR managers, and the employee viewing their own record.
     */
    private function gateSensitiveProfile(User $actor, User $employee): void
    {
        $profile = $employee->relationLoaded('employeeProfile') ? $employee->employeeProfile : null;
        if ($profile === null) {
            return;
        }

        $canSeeSensitive = $actor->isAdmin()
            || $actor->isSuperAdmin()
            || AccessDecision::can($actor, Capability::HR_MANAGE)
            || $actor->id === $employee->id;

        if (! $canSeeSensitive) {
            $profile->makeHidden(['social_insurance_number', 'fingerprint_number']);
        }
    }
}
