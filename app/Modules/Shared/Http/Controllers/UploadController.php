<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectSettingsService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\FileUploadValidator;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    /**
     * Magic bytes للتحقق من نوع الملف الحقيقي
     */
    protected array $magicBytes = [
        'jpeg' => ["\xFF\xD8\xFF"],
        'jpg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'gif' => ["\x47\x49\x46\x38\x37\x61", "\x47\x49\x46\x38\x39\x61"],
        'webp' => ["\x52\x49\x46\x46"],
        'pdf' => ["\x25\x50\x44\x46"],
        'doc' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        'docx' => ["\x50\x4B\x03\x04"],
        'xls' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        'xlsx' => ["\x50\x4B\x03\x04"],
        'zip' => ["\x50\x4B\x03\x04"],
    ];

    /**
     * أنواع الصور الآمنة (بدون SVG)
     */
    protected array $safeImageTypes = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

    public function __construct(
        protected ProjectSettingsService $settingsService,
    ) {}

    /**
     * Abort with 403 when the user may not view the project the attachment targets.
     * Routes through the unified engine — the same path ProjectPolicy::view uses —
     * so upload access stays in lockstep with project view authorization.
     */
    protected function authorizeProjectView(Request $request, Project $project): void
    {
        if (! AccessDecision::can($request->user(), Capability::PROJECTS_VIEW, $project)) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا المشروع');
        }
    }

    /**
     * رفع صورة
     */
    public function uploadImage(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::ATTACHMENTS_UPLOAD)) {
            abort(403, 'ليس لديك صلاحية رفع الملفات');
        }

        // منع SVG لأسباب أمنية (يمكن أن يحتوي على XSS)
        $allowedMimes = implode(',', $this->safeImageTypes);

        // M-17: no caller-controlled `folder` — images are stored on the private
        // disk under a fixed org-scoped path and served via an authenticated,
        // org-checked endpoint, not a world-readable public URL.
        $request->validate([
            'image' => "required|image|mimes:{$allowedMimes}|max:5120", // 5MB max
        ]);

        $file = $request->file('image');

        // التحقق من Magic Bytes
        if (! $this->validateMagicBytes($file)) {
            return response()->json([
                'message' => 'نوع الملف غير صالح أو تم التلاعب به',
            ], 422);
        }

        $orgId = $request->user()?->organization_id ?? 'shared';

        // إنشاء اسم فريد للملف باستخدام الامتداد الحقيقي
        $extension = $this->getSafeExtension($file);
        $filename = Str::uuid().'.'.$extension;

        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();
        $mimeType = $file->getMimeType();

        // حفظ الملف على القرص الخاص (private)
        $path = $file->storeAs("uploads/images/{$orgId}", $filename, 'local');

        // رابط مصادَق عليه (يتحقق من المؤسسة) بدل رابط عام
        $url = url("/api/upload/image/{$orgId}/{$filename}");

        return response()->json([
            'message' => 'تم رفع الصورة بنجاح',
            'url' => $url,
            'path' => $path,
            'original_name' => $originalName,
            'size' => $size,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Serve a previously-uploaded image from the private disk after an auth +
     * organization check (M-17). Replaces the old world-readable public URL.
     */
    public function serveImage(Request $request, string $orgId, string $filename): mixed
    {
        // Org floor: a non-super-admin may only fetch images in their own org folder.
        $user = $request->user();
        if (! $user->isSuperAdmin() && (string) $user->organization_id !== $orgId) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا الملف');
        }

        // Defense against traversal: a single name + extension, no "..", no slash.
        if (! preg_match('/^[A-Za-z0-9_-]+\.[a-z0-9]+$/i', $filename)) {
            abort(404);
        }

        $path = "uploads/images/{$orgId}/{$filename}";
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path);
    }

    /**
     * رفع شعار عام (للمستشفى)
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        // التحقق من صلاحية Admin
        if (! $request->user()->isAdmin() && ! $request->user()->isSuperAdmin()) {
            abort(403, 'ليس لديك صلاحية رفع الشعار');
        }

        $allowedMimes = implode(',', $this->safeImageTypes);

        $request->validate([
            'logo' => "required|image|mimes:{$allowedMimes}|max:2048", // 2MB max
        ]);

        $file = $request->file('logo');

        // التحقق من Magic Bytes
        if (! $this->validateMagicBytes($file)) {
            return response()->json([
                'message' => 'نوع الملف غير صالح أو تم التلاعب به',
            ], 422);
        }

        $extension = $this->getSafeExtension($file);
        $filename = 'organization_logo_'.time().'.'.$extension;

        // حفظ الشعار
        $path = $file->storeAs('public/logos', $filename);
        $url = Storage::url($path);

        return response()->json([
            'message' => 'تم رفع الشعار بنجاح',
            'url' => $url,
        ]);
    }

    /**
     * رفع مرفق للمشروع/المهمة (يطبق الإعدادات)
     */
    public function uploadAttachment(Request $request): JsonResponse
    {
        // الحصول على الإعدادات
        $maxSizeMB = $this->settingsService->getMaxAttachmentSizeMB();
        $allowedTypes = $this->settingsService->getAllowedFileTypes();
        $maxSizeKB = $maxSizeMB * 1024; // تحويل إلى كيلوبايت

        // إزالة SVG من الأنواع المسموحة لأسباب أمنية
        $allowedTypes = array_filter($allowedTypes, fn ($type) => strtolower($type) !== 'svg');

        // بناء قاعدة mimes
        $mimes = implode(',', $allowedTypes);

        $request->validate([
            'file' => "required|file|mimes:{$mimes}|max:{$maxSizeKB}",
            'project_id' => 'nullable|integer|exists:projects,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'folder' => 'nullable|string|max:50|alpha_dash',
        ], [
            'file.max' => "حجم الملف يجب أن يكون أقل من {$maxSizeMB} ميجابايت",
            'file.mimes' => 'نوع الملف غير مسموح. الأنواع المسموحة: '.implode(', ', $allowedTypes),
        ]);

        $file = $request->file('file');

        // التحقق من صلاحية الوصول للمشروع/المهمة المرفقة
        if ($request->has('project_id')) {
            $project = Project::find($request->project_id);
            if ($project) {
                $this->authorizeProjectView($request, $project);
            }
        }

        if ($request->has('task_id')) {
            $task = Task::find($request->task_id);
            if ($task && $task->project_id) {
                $project = Project::find($task->project_id);
                if ($project) {
                    $this->authorizeProjectView($request, $project);
                }
            }
        }

        // التحقق الصارم من نوع الملف الحقيقي عبر finfo (يمنع RIFF/WAV بصيغة .webp وغيرها)
        try {
            $this->validateUploadedFile($file, FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first('file') ?: 'نوع الملف غير صالح أو تم التلاعب به',
                'errors' => $e->errors(),
            ], 422);
        }

        $folder = $request->input('folder', 'attachments');

        // إضافة project_id أو task_id للمسار إن وُجد مع التحقق من الصلاحية
        if ($request->has('project_id')) {
            $projectId = (int) $request->project_id;
            $folder = "projects/{$projectId}/attachments";
        } elseif ($request->has('task_id')) {
            $taskId = (int) $request->task_id;
            $folder = "tasks/{$taskId}/attachments";
        }

        // إنشاء اسم فريد للملف مع الاحتفاظ بالامتداد الآمن
        $originalName = $file->getClientOriginalName();
        $extension = $this->getSafeExtension($file);
        $filename = Str::uuid().'.'.$extension;

        // ponytail: store on local (private) disk — download via /api/attachments/{id}/download
        $path = $file->storeAs($folder, $filename, 'local');
        $url = null;

        // تسجيل النشاط إذا كان مرتبطاً بمشروع أو مهمة
        if ($request->has('project_id')) {
            $projectId = (int) $request->project_id;
            // اشتقاق organization_id من الـ Project نفسه (source 1/2 في الـ Resolver)،
            // لا من $request->user()->organization_id.
            $projectOrgId = app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Project::class, $projectId);
            ActivityLog::create([
                'loggable_type' => Project::class,
                'loggable_id' => $projectId,
                'user_id' => $request->user()?->id,
                'action' => 'attachment_added',
                'description' => "تم رفع مرفق: {$originalName}",
                'organization_id' => $projectOrgId,
                'new_values' => [
                    'file_name' => $originalName,
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                ],
                'ip_address' => $request->ip(),
            ]);
        } elseif ($request->has('task_id')) {
            $taskId = (int) $request->task_id;
            $task = Task::find($taskId);
            $taskOrgId = app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Task::class, $taskId);
            ActivityLog::create([
                'loggable_type' => Task::class,
                'loggable_id' => $taskId,
                'user_id' => $request->user()?->id,
                'action' => 'attachment_added',
                'description' => "تم رفع مرفق: {$originalName}",
                'organization_id' => $taskOrgId,
                'new_values' => [
                    'file_name' => $originalName,
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                    'task_title' => $task?->title,
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'message' => 'تم رفع الملف بنجاح',
            'path' => $path,
            'original_name' => $originalName,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * التحقق الصارم من الملف: finfo لكشف MIME الحقيقي + cross-check مع امتداد الملف + حد الحجم.
     *
     * @param  array<int, string>  $allowedExtensions
     *
     * @throws ValidationException
     */
    private function validateUploadedFile(UploadedFile $file, array $allowedExtensions): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file->getPathname()) ?: '';

        $maxBytes = 10 * 1024 * 1024;
        if (filesize($file->getPathname()) > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => [sprintf('حجم الملف يتجاوز الحد المسموح (%d ميجابايت).', (int) ($maxBytes / 1024 / 1024))],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, array_map('strtolower', $allowedExtensions), true)) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف غير مسموح. الأنواع المسموحة: '.implode(', ', $allowedExtensions)],
            ]);
        }

        $mimeMap = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'doc' => ['application/msword', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'txt' => ['text/plain'],
        ];

        $allowed = $mimeMap[$extension] ?? null;
        if ($allowed === null) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف غير مسموح. الأنواع المسموحة: '.implode(', ', $allowedExtensions)],
            ]);
        }

        if (! in_array($detected, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف المكتشف لا يطابق الامتداد. تم رفض الملف لأسباب أمنية.'],
            ]);
        }
    }

    /**
     * التحقق من Magic Bytes للملف
     *
     * @param  UploadedFile  $file
     */
    protected function validateMagicBytes($file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // إذا لم يكن الامتداد في قائمة Magic Bytes، نسمح به (للملفات النصية مثلاً)
        if (! isset($this->magicBytes[$extension])) {
            // للصور نتحقق من MIME type
            if (in_array($extension, $this->safeImageTypes)) {
                $mimeType = $file->getMimeType();

                return Str::startsWith($mimeType, 'image/');
            }

            return true;
        }

        // قراءة أول بايتات من الملف
        $handle = fopen($file->getPathname(), 'rb');
        if (! $handle) {
            return false;
        }

        $bytes = fread($handle, 16);
        fclose($handle);

        if ($bytes === false) {
            return false;
        }

        // التحقق من Magic Bytes
        foreach ($this->magicBytes[$extension] as $magic) {
            if (substr($bytes, 0, strlen($magic)) === $magic) {
                return true;
            }
        }

        return false;
    }

    /**
     * الحصول على امتداد آمن للملف
     *
     * @param  UploadedFile  $file
     */
    protected function getSafeExtension($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // التحقق من أن الامتداد آمن (أحرف وأرقام فقط)
        if (! preg_match('/^[a-z0-9]+$/', $extension)) {
            // استخدام MIME type لتحديد الامتداد
            $mimeType = $file->getMimeType();
            $mimeExtensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            ];

            return $mimeExtensions[$mimeType] ?? 'bin';
        }

        return $extension;
    }
}
