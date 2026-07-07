<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DepartmentPathTest — materialized-path subtree resolution (Phase 6, Task 1).
 *
 * The observer keeps departments.path populated as /{ancestorIds}/{self}/ on
 * create and on a parent move; descendant resolution and the engine subtree
 * expansion both read the indexed path instead of recursing in PHP.
 */
class DepartmentPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_path_is_maintained_and_descendants_resolve_via_path(): void
    {
        $sector = Department::factory()->create(['parent_id' => null]);
        $child = Department::factory()->create(['parent_id' => $sector->id]);
        $grand = Department::factory()->create(['parent_id' => $child->id]);

        $this->assertSame("/{$sector->id}/", $sector->fresh()->path);
        $this->assertSame("/{$sector->id}/{$child->id}/", $child->fresh()->path);
        $this->assertSame("/{$sector->id}/{$child->id}/{$grand->id}/", $grand->fresh()->path);

        $ids = $sector->fresh()->descendantIdsViaPath();
        $this->assertEqualsCanonicalizing([$sector->id, $child->id, $grand->id], $ids);
    }

    public function test_descendant_resolution_does_not_match_sibling_prefixes(): void
    {
        // id collisions like /1/ vs /11/ must not be conflated by the LIKE match.
        $a = Department::factory()->create(['parent_id' => null]);
        $childOfA = Department::factory()->create(['parent_id' => $a->id]);
        $unrelated = Department::factory()->create(['parent_id' => null]);

        $ids = $a->fresh()->descendantIdsViaPath();

        $this->assertEqualsCanonicalizing([$a->id, $childOfA->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_moving_a_subtree_rewrites_descendant_paths(): void
    {
        $oldParent = Department::factory()->create(['parent_id' => null]);
        $newParent = Department::factory()->create(['parent_id' => null]);
        $node = Department::factory()->create(['parent_id' => $oldParent->id]);
        $leaf = Department::factory()->create(['parent_id' => $node->id]);

        $node->update(['parent_id' => $newParent->id]);

        $this->assertSame("/{$newParent->id}/{$node->id}/", $node->fresh()->path);
        $this->assertSame("/{$newParent->id}/{$node->id}/{$leaf->id}/", $leaf->fresh()->path);

        // the whole moved subtree now resolves under the new parent
        $ids = $newParent->fresh()->descendantIdsViaPath();
        $this->assertEqualsCanonicalizing([$newParent->id, $node->id, $leaf->id], $ids);

        // and no longer under the old parent
        $this->assertEqualsCanonicalizing([$oldParent->id], $oldParent->fresh()->descendantIdsViaPath());
    }

    public function test_engine_subtree_expansion_uses_path(): void
    {
        $sector = Department::factory()->create(['parent_id' => null]);
        $child = Department::factory()->create(['parent_id' => $sector->id]);
        $grand = Department::factory()->create(['parent_id' => $child->id]);

        $expanded = AccessDecision::subtreeDepartmentIds([$sector->id]);

        $this->assertEqualsCanonicalizing([$sector->id, $child->id, $grand->id], $expanded);
    }

    public function test_rebuild_command_repairs_corrupted_paths(): void
    {
        $sector = Department::factory()->create(['parent_id' => null]);
        $child = Department::factory()->create(['parent_id' => $sector->id]);

        // simulate drift: wipe the persisted paths without firing observers
        DB::table('departments')->update(['path' => null]);
        $this->assertNull($sector->fresh()->path);

        $this->artisan('roles:rebuild-department-paths')->assertExitCode(0);

        $this->assertSame("/{$sector->id}/", $sector->fresh()->path);
        $this->assertSame("/{$sector->id}/{$child->id}/", $child->fresh()->path);
    }
}
