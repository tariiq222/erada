<?php

namespace Tests\Unit\Core\Enums;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Enums\Permission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionEnumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_seeder_produces_all_enum_values(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $enumValues = Permission::values();
        $dbNames = SpatiePermission::pluck('name')->all();

        $missingFromDb = array_diff($enumValues, $dbNames);
        $extraInDb = array_diff($dbNames, $enumValues);

        $this->assertEmpty(
            $missingFromDb,
            'Seeder did not create these enum permissions in DB: '.implode(', ', $missingFromDb)
        );
        $this->assertEmpty(
            $extraInDb,
            'Seeder created DB permissions that are not in the enum: '.implode(', ', $extraInDb)
        );
        $this->assertEqualsCanonicalizing(
            $enumValues,
            $dbNames,
            'Set of enum values must be byte-equal to set of DB permission names'
        );
    }

    public function test_seeder_produces_no_extra_permissions_not_in_enum(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $enumValues = Permission::values();
        $dbNames = SpatiePermission::pluck('name')->all();

        $extraInDb = array_diff($dbNames, $enumValues);

        $this->assertEmpty(
            $extraInDb,
            'Seeder created DB permissions that are not in the enum: '.implode(', ', $extraInDb)
        );
    }

    public function test_all_enum_values_are_non_empty_strings(): void
    {
        foreach (Permission::cases() as $case) {
            $value = $case->value;

            $this->assertIsString($value, "Case {$case->name} value is not a string");
            $this->assertNotEmpty(trim($value), "Case {$case->name} value is empty or whitespace");
            $this->assertSame($value, trim($value), "Case {$case->name} value has leading/trailing whitespace");
            // Permission values use snake_case or dot-notation, and the Meetings
            // module intentionally uses kebab-case (e.g. view-meetings,
            // manage-meetings, record-decisions) which is seeded system-wide.
            // Allow hyphens as a valid word separator alongside underscores.
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*$/',
                $value,
                "Case {$case->name} value '{$value}' is not valid snake/kebab/dot-notation"
            );
        }
    }

    public function test_enum_has_expected_minimum_count(): void
    {
        // السلّم المسطّح (view_projects / view_department_projects / view_own_projects /
        // view_tasks / view_department_tasks / view_own_tasks) أُلغي في Wave 4-7 لصالح
        // Capability::PROJECTS_VIEW / Capability::TASKS_VIEW + منح المحرك. الرؤية صار
        // تُدار عبر Capability::all() بدلاً من Permission. الحد الأدنى للـ enum صار
        // أقل؛ يفحص أن المحرك يملك الحد الأدنى من القدرات لضمان عدم حذف موجة كاملة.
        $count = count(Permission::cases());

        $this->assertGreaterThanOrEqual(
            50,
            $count,
            "Permission enum must contain at least 50 cases; found {$count}. ".
            'Lower bound guards against accidental deletions during the P2-E refactor.'
        );

        $capabilityCount = count(Capability::all());

        $this->assertGreaterThanOrEqual(
            70,
            $capabilityCount,
            "Engine Capability must contain at least 70 cases; found {$capabilityCount}. ".
            'Lower bound guards against accidental deletions during the P2-E refactor.'
        );
    }
}
