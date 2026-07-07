<?php

namespace App\Modules\HR\Observers;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Shared\Models\ActivityLog;

class DepartmentObserver
{
    /**
     * Keep the materialized path (departments.path) in sync on create and move.
     *
     * Path format: /{ancestorIds}/{self}/ (e.g. /1/4/17/). Reads of the subtree
     * use an indexed `where path like` query (O(1) index), so the only cost paid
     * here is on the rare structural change.
     */
    public function saved(Department $department): void
    {
        $expected = static::expectedPath($department);

        if ($department->path !== $expected) {
            static::persistPath($department, $expected);
        }

        // On a move (or first insert), recompute the whole subtree's paths EXPLICITLY.
        // touch() would NOT work here: it leaves parent_id clean, so a saving() guard
        // on isDirty('parent_id') would never re-run. We recompute each descendant's
        // path directly from its (already-updated) parent instead.
        // ponytail: O(subtree) per move -- acceptable because moves are rare; the read
        // path stays O(1) via the path index.
        if ($department->wasChanged('parent_id') || $department->wasRecentlyCreated) {
            static::repathChildren($department);

            // The department tree changed: AccessDecision's scope-chain / subtree
            // caches are keyed by department identity and are now stale.
            AccessDecision::flushCache();
        }
    }

    /**
     * Persist a path via the query builder (not a nested model save).
     *
     * A nested updateQuietly()/save() fired from inside the saved() event of a
     * create() is silently dropped by Eloquent (the outer insert resets the model's
     * sync state afterwards). A direct where(id)->update() bypasses the model
     * lifecycle entirely, so it neither re-fires observers nor gets swallowed, while
     * we keep the in-memory attribute in sync for any downstream reads.
     */
    protected static function persistPath(Department $department, string $path): void
    {
        Department::withTrashed()
            ->where('id', $department->id)
            ->update(['path' => $path]);

        $department->setAttribute('path', $path);
        $department->syncOriginalAttribute('path');
    }

    /**
     * The canonical path for a department: its parent's path with its own id appended.
     */
    protected static function expectedPath(Department $department): string
    {
        $parentPath = $department->parent_id
            ? (Department::find($department->parent_id)?->path ?? '/')
            : '/';

        return rtrim($parentPath, '/')."/{$department->id}/";
    }

    /**
     * Recursively rewrite every descendant's path from its (already-updated) parent.
     */
    protected static function repathChildren(Department $parent): void
    {
        foreach (Department::withTrashed()->where('parent_id', $parent->id)->get() as $child) {
            $newPath = rtrim($parent->path, '/')."/{$child->id}/";
            if ($child->path !== $newPath) {
                static::persistPath($child, $newPath);
            }
            static::repathChildren($child); // recurse with the child's fresh path
        }
    }

    public function updated(Department $department): void
    {
        // Audit structure changes as privileged operations FIRST: re-parenting or a
        // manager swap silently re-grants/revokes visibility across a whole subtree.
        if ($department->wasChanged('manager_id') || $department->wasChanged('parent_id')) {
            static::auditRestructure($department);
        }

        if (! $department->wasChanged('manager_id')) {
            return;
        }

        $service = app(ScopedDepartmentRoleSyncService::class);

        $previousManagerId = $department->getOriginal('manager_id');
        if ($previousManagerId !== null) {
            $previous = User::find($previousManagerId);
            if ($previous !== null) {
                $service->syncUser($previous);
            }
        }

        if ($department->manager_id !== null) {
            $current = User::find($department->manager_id);
            if ($current !== null) {
                $service->syncUser($current);
            }
        }
    }

    /**
     * Write an audit row recording a department restructure (manager/parent change).
     * Description/reason are user-facing audit content and follow the existing
     * Arabic ActivityLog convention (see ActivityLogService).
     */
    protected static function auditRestructure(Department $department): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'department_restructured',
            'description' => "تغيير هيكل القسم: {$department->name}",
            'loggable_type' => Department::class,
            'loggable_id' => $department->id,
            'old_values' => [
                'manager_id' => $department->getOriginal('manager_id'),
                'parent_id' => $department->getOriginal('parent_id'),
            ],
            'new_values' => [
                'manager_id' => $department->manager_id,
                'parent_id' => $department->parent_id,
            ],
            'reason' => 'إعادة هيكلة القسم تُعيد توزيع صلاحيات الرؤية على الشجرة الفرعية',
        ]);
    }

    public function deleted(Department $department): void
    {
        // No FK from model_has_scoped_roles to departments -- drop orphaned scope rows.
        ScopedRole::where('scope_type', 'department')
            ->where('scope_id', $department->id)
            ->delete();

        // Roles were revoked en masse (bypassing model events) and a node left the
        // tree; flush the whole decision cache to be safe.
        AccessDecision::flushCache();
    }
}
