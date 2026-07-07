<?php

namespace App\Modules\Tasks\Repositories\Contracts;

use App\Modules\Core\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface TaskRepositoryInterface
{
    /**
     * الاستعلام الأساسي مع العلاقات
     */
    public function baseQuery(): Builder;

    /**
     * قائمة المهام مع الفلاتر والتصفح
     */
    public function getPaginated(array $filters, int $perPage = 15, ?User $user = null): LengthAwarePaginator;

    /**
     * مهام المستخدم الشخصية مع الفلاتر
     */
    public function getUserTasksPaginated(int $userId, array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * إيجاد مهمة مع علاقاتها الكاملة
     */
    public function findWithRelations(int $id): ?Task;

    /**
     * إنشاء مهمة جديدة
     */
    public function create(array $data): Task;

    /**
     * تحديث مهمة
     */
    public function update(Task $task, array $data): Task;

    /**
     * حذف مهمة وفرعياتها
     */
    public function delete(Task $task): bool;

    /**
     * إحصائيات المهام
     */
    public function getStats(array $filters = [], ?User $user = null): array;
}
