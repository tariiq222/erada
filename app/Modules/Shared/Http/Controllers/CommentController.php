<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Requests\DeleteCommentRequest;
use App\Modules\Shared\Http\Requests\StoreCommentRequest;
use App\Modules\Shared\Http\Requests\UpdateCommentRequest;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Notifications\MentionedInCommentNotification;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use App\Modules\Shared\Services\FileUploadValidator;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommentController extends Controller
{
    /**
     * عرض قائمة التعليقات لعنصر محدد
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'commentable_type' => 'required|in:task,project',
            'commentable_id' => 'required|integer',
        ]);

        $type = $request->commentable_type;
        $id = $request->commentable_id;

        // التحقق من وجود العنصر
        $model = match ($type) {
            'task' => Task::findOrFail($id),
            'project' => Project::findOrFail($id),
        };

        // التحقق من صلاحية الوصول
        $this->authorizeCommentableAccess($request->user(), $model);

        $comments = $model->comments()
            ->with(['user:id,name,email', 'attachments'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Single batched lookup for all @mentioned names across the result set.
        // One whereIn query total, then keyBy('name') gives O(1) per-comment lookup.
        $allMentionedNames = [];
        foreach ($comments as $comment) {
            if (preg_match_all('/@([^\s@]+)/', $comment->content, $matches)) {
                foreach ($matches[1] as $name) {
                    $allMentionedNames[$name] = true;
                }
            }
        }

        $mentionedUsersByName = User::whereIn('name', array_keys($allMentionedNames))
            ->where('organization_id', $request->user()->organization_id) // منع التسرب عبر المؤسسات — symmetric مع store:169
            ->select('id', 'name')
            ->get()
            ->keyBy('name');

        $payload = $comments->map(function ($comment) use ($mentionedUsersByName) {
            $mentionedUsers = [];
            if (preg_match_all('/@([^\s@]+)/', $comment->content, $matches)) {
                $seen = [];
                foreach ($matches[1] as $name) {
                    if (isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;
                    $user = $mentionedUsersByName->get($name);
                    if ($user) {
                        $mentionedUsers[] = $user->toArray();
                    }
                }
            }

            return [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => $comment->user,
                'mentioned_users' => $mentionedUsers,
                'attachments' => $comment->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'file_type' => $a->file_type,
                    'file_size' => $a->file_size,
                    'formatted_size' => $a->formatted_size,
                    'download_url' => url("/api/attachments/{$a->id}/download"),
                ]),
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
            ];
        });

        return response()->json($payload);
    }

    /**
     * إضافة تعليق جديد
     */
    public function store(StoreCommentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $type = $validated['commentable_type'];
        $id = $validated['commentable_id'];

        // التحقق من وجود العنصر
        $model = match ($type) {
            'task' => Task::findOrFail($id),
            'project' => Project::findOrFail($id),
        };

        // إنشاء التعليق
        $comment = $model->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        // رفع المرفقات إن وجدت
        $attachments = [];
        $attachmentIds = [];
        if ($request->hasFile('attachments')) {
            $uploadValidator = app(FileUploadValidator::class);
            foreach ($request->file('attachments') as $file) {
                $uploadValidator->validate($file, FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS);
                $path = $file->store("comments/{$comment->id}", 'local');
                $attachment = $comment->attachments()->create([
                    'user_id' => $request->user()->id,
                    'name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
                $attachments[] = [
                    'id' => $attachment->id,
                    'name' => $attachment->name,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'formatted_size' => $attachment->formatted_size,
                    'download_url' => url("/api/attachments/{$attachment->id}/download"),
                ];
                $attachmentIds[] = $attachment->id;
            }
        }

        // تسجيل نشاط إضافة تعليق
        $this->logCommentActivity($model, $type, 'comment_added', null, [
            'comment_id' => $comment->id,
            'has_attachments' => count($attachments) > 0,
            'attachments_count' => count($attachments),
            'attachment_ids' => $attachmentIds,
        ]);

        // تحميل العلاقات
        $comment->load('user:id,name,email');

        // استخراج أسماء المستخدمين المذكورين وإرسال الإشعارات
        $mentionedUsers = [];
        if (! empty($validated['mentioned_users'])) {
            $usersToNotify = User::whereIn('id', $validated['mentioned_users'])
                ->where('id', '!=', $request->user()->id) // لا ترسل إشعار للمستخدم نفسه
                ->where('organization_id', $request->user()->organization_id) // منع التسرب عبر المؤسسات
                ->get();

            $mentionedUsers = $usersToNotify->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->toArray();

            // إرسال إشعارات للمستخدمين المذكورين
            $contextName = $model instanceof Task ? $model->title : $model->name;
            foreach ($usersToNotify as $userToNotify) {
                $userToNotify->notify(new MentionedInCommentNotification(
                    $comment,
                    $request->user(),
                    $type,
                    $id,
                    $contextName
                ));
            }
        }

        return response()->json([
            'message' => 'تم إضافة التعليق بنجاح',
            'comment' => [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => $comment->user,
                'mentioned_users' => $mentionedUsers,
                'attachments' => $attachments,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
            ],
        ], 201);
    }

    /**
     * تحديث تعليق
     */
    public function update(UpdateCommentRequest $request, string $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);

        // Defense-in-depth: even though UpdateCommentRequest already gates on
        // the engine (COMMENTS_VIEW on the commentable), re-check the
        // polymorphic parent here so direct controller dispatch / future route
        // reshapes cannot bypass org isolation. Mirrors deleteAttachment().
        $this->authorizeCommentableParent($request->user(), $comment);

        $comment->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث التعليق بنجاح',
            'comment' => $comment->fresh(['user:id,name,email']),
        ]);
    }

    /**
     * حذف تعليق
     */
    public function destroy(DeleteCommentRequest $request, string $id): JsonResponse
    {
        $comment = Comment::with('attachments')->findOrFail($id);

        // Defense-in-depth: even though DeleteCommentRequest already gates on
        // the engine (COMMENTS_VIEW on the commentable), re-check the
        // polymorphic parent here so direct controller dispatch / future route
        // reshapes cannot bypass org isolation. Mirrors deleteAttachment().
        $this->authorizeCommentableParent($request->user(), $comment);

        // حفظ معلومات التعليق للتسجيل قبل الحذف
        $commentableType = $comment->commentable_type;
        $commentableId = $comment->commentable_id;
        $attachmentsCount = $comment->attachments->count();
        $attachmentIds = $comment->attachments->pluck('id')->all();

        // حذف منطقي للمرفقات فقط: تبقى الملفات الخاصة حتى مسار purge/force-delete.
        // Manual loop avoids the F2-audit attachment leak: deleteQuietly on the
        // parent Comment suppresses model events, so any cascade-cleanup an
        // Attachment observer might do would be skipped. Soft-deleting the
        // attachments here directly guarantees no orphan rows survive the
        // parent soft-delete.
        foreach ($comment->attachments as $attachment) {
            $attachment->delete();
        }

        $comment->deleteQuietly();

        // تسجيل نشاط حذف التعليق
        $model = $commentableType === Task::class ? Task::find($commentableId) : Project::find($commentableId);
        if ($model) {
            $type = $commentableType === Task::class ? 'task' : 'project';
            $this->logCommentActivity($model, $type, 'comment_deleted', [
                'attachments_count' => $attachmentsCount,
                'attachment_ids' => $attachmentIds,
            ], null);
        }

        return response()->json([
            'message' => 'تم حذف التعليق بنجاح',
        ]);
    }

    /**
     * حذف مرفق من تعليق
     */
    public function deleteAttachment(Request $request, string $commentId, string $attachmentId): JsonResponse
    {
        $comment = Comment::findOrFail($commentId);

        // التحقق من صلاحية الوصول للكيان الأب أولاً (منع IDOR عبر المنظمات)
        $this->authorizeCommentableParent($request->user(), $comment);

        // التحقق من أن المستخدم هو صاحب التعليق، أو super_admin، أو admin (دور وظيفي بمنح COMMENTS_DELETE)، أو يملك صلاحية حذف أي تعليق.
        $canDelete = $comment->user_id === $request->user()->id
            || AccessDecision::can($request->user(), Capability::COMMENTS_DELETE);

        if (! $canDelete) {
            return response()->json([
                'message' => 'غير مصرح لك بحذف هذا المرفق',
            ], 403);
        }

        $attachment = Attachment::where('attachable_type', Comment::class)
            ->where('attachable_id', $commentId)
            ->where('id', $attachmentId)
            ->firstOrFail();

        // حذف منطقي فقط: يبقى الملف الخاص حتى مسار purge/force-delete بعد مدة الاحتفاظ.
        $attachment->delete();

        // تسجيل نشاط حذف المرفق
        $commentableType = $comment->commentable_type;
        $commentableId = $comment->commentable_id;
        $model = $commentableType === Task::class ? Task::find($commentableId) : Project::find($commentableId);
        if ($model) {
            $type = $commentableType === Task::class ? 'task' : 'project';
            $this->logCommentActivity($model, $type, 'attachment_deleted', [
                'attachment_id' => $attachment->id,
            ], null);
        }

        return response()->json([
            'message' => 'تم حذف المرفق بنجاح',
        ]);
    }

    /**
     * إضافة مرفقات لتعليق موجود
     */
    public function addAttachments(Request $request, string $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);

        // التحقق من أن المستخدم هو صاحب التعليق
        if ($comment->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'غير مصرح لك بإضافة مرفقات لهذا التعليق',
            ], 403);
        }

        $request->validate([
            'attachments' => 'required|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt|max:10240', // 10MB max per file
        ]);

        $attachments = [];
        $attachmentIds = [];
        $uploadValidator = app(FileUploadValidator::class);
        foreach ($request->file('attachments') as $file) {
            $uploadValidator->validate($file, FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS);
            $path = $file->store("comments/{$comment->id}", 'local');
            $attachment = $comment->attachments()->create([
                'user_id' => $request->user()->id,
                'name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
            $attachments[] = [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
                'formatted_size' => $attachment->formatted_size,
                'download_url' => url("/api/attachments/{$attachment->id}/download"),
            ];
            $attachmentIds[] = $attachment->id;
        }

        // تسجيل نشاط إضافة مرفقات
        $commentableType = $comment->commentable_type;
        $commentableId = $comment->commentable_id;
        $model = $commentableType === Task::class ? Task::find($commentableId) : Project::find($commentableId);
        if ($model) {
            $type = $commentableType === Task::class ? 'task' : 'project';
            $this->logCommentActivity($model, $type, 'attachment_added', null, [
                'attachments_count' => count($attachments),
                'attachment_ids' => $attachmentIds,
            ]);
        }

        return response()->json([
            'message' => 'تم إضافة المرفقات بنجاح',
            'attachments' => $attachments,
        ]);
    }

    /**
     * التحقق من صلاحية الوصول للعنصر (مشروع أو مهمة)
     *
     * @param  User  $user
     * @param  Task|Project  $model
     *
     * @throws HttpException
     */
    protected function authorizeCommentableAccess($user, $model): void
    {
        // Super Admin لديه صلاحية كاملة
        if ($user->isSuperAdmin()) {
            return;
        }

        $organizationId = $this->commentableOrganizationId($model);
        if ($user->organization_id === null || $organizationId === null || $user->organization_id !== $organizationId) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        // تحديد المشروع
        $project = $model instanceof Task ? $model->project : $model;

        // للمهام الشخصية أو غير المرتبطة بمشروع: المكلف/المالك/المنشئ فقط بعد تحقق المؤسسة أعلاه.
        if ($model instanceof Task && ! $project) {
            if (in_array($user->id, array_filter([$model->assigned_to, $model->owner_id, $model->created_by]), true)) {
                return;
            }

            abort(403, 'ليس لديك صلاحية الوصول');
        }

        // Admin يمكنه الوصول لمشاريع قسمه
        if ($user->isAdmin()) {
            if ($project->department_id === $user->department_id) {
                return;
            }
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        // مدير المشروع (scoped manager)
        if ($user->isProjectAdmin($project)) {
            return;
        }

        // منشئ المشروع
        if ($project->created_by === $user->id) {
            return;
        }

        // للمهام: المكلف بالمهمة أو منشئها أو مالكها
        if ($model instanceof Task) {
            if (in_array($user->id, array_filter([$model->assigned_to, $model->owner_id, $model->created_by]), true)) {
                return;
            }
        }

        // أعضاء المشروع (يشمل مدير المشروع عبر الدور المُسنَد)
        $isMember = $project->members()->where('user_id', $user->id)->exists();
        if ($isMember) {
            return;
        }

        abort(403, 'ليس لديك صلاحية الوصول');
    }

    /**
     * التحقق من صلاحية الوصول للكيان الأب لتعليق موجود (منع IDOR في التعديل/الحذف).
     * يحمّل المشروع/المهمة المرتبطة بالتعليق ويعيد استخدام منطق الوصول الموحّد.
     *
     * @throws HttpException
     */
    protected function authorizeCommentableParent(User $user, Comment $comment): void
    {
        $model = $comment->commentable_type === Task::class
            ? Task::find($comment->commentable_id)
            : Project::find($comment->commentable_id);

        // كيان أب محذوف/يتيم: ارفض بدلاً من التخطي الصامت (fail-closed).
        if (! $model) {
            abort(404, 'Commentable parent not found.');
        }

        // تحقق صريح من عزل المؤسسة قبل أي منطق وصول آخر — حتى لو فُقد
        // نموذج الأب، نضمن أن المستخدم ينتمي لنفس مؤسسة التعليق.
        $commentOrgId = $this->commentableOrganizationId($model);
        if ($commentOrgId !== null
            && $user->organization_id !== null
            && (int) $user->organization_id !== (int) $commentOrgId) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        $this->authorizeCommentableAccess($user, $model);
    }

    /**
     * تحديد مؤسسة العنصر القابل للتعليق.
     *
     * @param  Task|Project  $model
     */
    protected function commentableOrganizationId($model): ?int
    {
        if ($model instanceof Project) {
            return $model->organization_id;
        }

        if ($model instanceof Task) {
            if ($model->project) {
                return $model->project->organization_id;
            }

            if ($model->department) {
                return $model->department->organization_id;
            }

            $relatedUserId = $model->owner_id ?? $model->assigned_to ?? $model->created_by;
            if ($relatedUserId) {
                return User::query()->whereKey($relatedUserId)->value('organization_id');
            }
        }

        return null;
    }

    /**
     * تسجيل نشاط متعلق بالتعليقات
     *
     * @param  Task|Project  $model
     * @param  string  $type  نوع العنصر (task أو project)
     * @param  string  $action  نوع العملية
     * @param  array|null  $oldValues  القيم القديمة
     * @param  array|null  $newValues  القيم الجديدة
     */
    protected function logCommentActivity($model, string $type, string $action, ?array $oldValues, ?array $newValues): void
    {
        $userId = auth()->id()
            ?? auth('sanctum')->id()
            ?? request()->user()?->id;

        $loggableType = $type === 'task' ? Task::class : Project::class;

        // اشتقاق organization_id من الـ loggable نفسه (لا من $request->user()).
        $organizationId = app(ActivityLogOrganizationResolver::class)
            ->resolveForLoggable($loggableType, $model->id);

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'loggable_type' => $loggableType,
            'loggable_id' => $model->id,
            'organization_id' => $organizationId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }
}
