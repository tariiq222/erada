<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\HR\Http\Requests\DeleteEmployeeCertificateRequest;
use App\Modules\HR\Http\Requests\DownloadEmployeeCertificateRequest;
use App\Modules\HR\Http\Requests\StoreEmployeeCertificateRequest;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Support\EmployeeOrgGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EmployeeCertificateController extends Controller
{
    public function store(StoreEmployeeCertificateRequest $request, User $employee): JsonResponse
    {
        // Authz + cross-org + null-org checks owned by StoreEmployeeCertificateRequest.
        $actor = $request->user();
        $guard = app(EmployeeOrgGuard::class);

        // Belt-and-braces same-org re-assertion.
        $guard->abortUnlessSameOrganization($actor, $guard->employeeOrgId($employee));

        $data = $request->validated();

        $profile = $employee->employeeProfile;
        if (! $profile) {
            if (! $employee->department_id) {
                return response()->json([
                    'message' => 'لا يوجد ملف وظيفي للموظف ولا يمكن إنشاؤه بدون قسم مرتبط بالمستخدم',
                ], 422);
            }

            $profile = DB::transaction(function () use ($employee) {
                return EmployeeProfile::create([
                    'user_id' => $employee->id,
                    'employee_no' => 'EMP-'.Str::upper(Str::random(8)),
                    'hire_date' => now()->toDateString(),
                    'employment_type' => 'full_time',
                    'employment_status' => 'active',
                ]);
            });
        }

        $upload = $request->file('file');
        $extension = $upload->getClientOriginalExtension() ?: $upload->extension();
        $filename = Str::uuid()->toString().'.'.$extension;
        $relativePath = "hr/employees/{$employee->id}/{$data['type']}/{$filename}";

        Storage::disk('local')->putFileAs(
            "hr/employees/{$employee->id}/{$data['type']}",
            $upload,
            $filename,
        );

        $certificate = EmployeeCertificate::create([
            'employee_profile_id' => $profile->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'file_path' => $relativePath,
            'file_name' => $upload->getClientOriginalName(),
            'mime_type' => $upload->getClientMimeType(),
            'file_size' => $upload->getSize(),
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($certificate->fresh(), 201);
    }

    public function destroy(DeleteEmployeeCertificateRequest $request, EmployeeCertificate $certificate): JsonResponse
    {
        // Authz + cross-org + orphan-cert guard owned by DeleteEmployeeCertificateRequest.
        DB::transaction(function () use ($certificate) {
            if ($certificate->file_path && Storage::disk('local')->exists($certificate->file_path)) {
                Storage::disk('local')->delete($certificate->file_path);
            }

            $certificate->delete();
        });

        return response()->json(null, 204);
    }

    public function download(DownloadEmployeeCertificateRequest $request, EmployeeCertificate $certificate): StreamedResponse
    {
        // Authz + cross-org floor + orphan-cert guard owned by
        // DownloadEmployeeCertificateRequest. File-on-disk check stays here —
        // it's a runtime I/O state error, not an AuthZ decision.
        if (! $certificate->file_path || ! Storage::disk('local')->exists($certificate->file_path)) {
            throw new NotFoundHttpException('الملف غير موجود على القرص');
        }

        return Storage::disk('local')->download(
            $certificate->file_path,
            $certificate->file_name ?? basename($certificate->file_path),
        );
    }
}
