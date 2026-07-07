<?php

namespace App\Console\Commands;

use App\Modules\HR\Models\Department;
use Illuminate\Console\Command;

class RebuildDepartmentPaths extends Command
{
    protected $signature = 'roles:rebuild-department-paths';

    protected $description = 'Rebuild departments.path materialized paths from the parent_id tree (one-time backfill / repair)';

    public function handle(): int
    {
        $count = 0;

        // Walk from each root downward, writing each node's path from its parent.
        // updateQuietly avoids re-firing the observer (which would recurse again).
        Department::query()
            ->whereNull('parent_id')
            ->withTrashed()
            ->orderBy('id')
            ->each(function (Department $root) use (&$count) {
                $count += $this->rebuildSubtree($root, '/');
            });

        $this->info("Rebuilt paths for {$count} departments.");

        return self::SUCCESS;
    }

    /**
     * Set $node's path from $parentPath, then recurse into its children.
     * Returns the number of departments written.
     */
    private function rebuildSubtree(Department $node, string $parentPath): int
    {
        $path = rtrim($parentPath, '/')."/{$node->id}/";

        if ($node->path !== $path) {
            // Direct query-builder update: bypasses the model lifecycle (no observer
            // recursion) and persists reliably even when re-pathing in bulk.
            Department::withTrashed()->where('id', $node->id)->update(['path' => $path]);
            $node->setAttribute('path', $path);
        }

        $written = 1;

        Department::query()
            ->where('parent_id', $node->id)
            ->withTrashed()
            ->orderBy('id')
            ->each(function (Department $child) use ($path, &$written) {
                $written += $this->rebuildSubtree($child, $path);
            });

        return $written;
    }
}
