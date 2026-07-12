<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssignmentScopeTest extends TestCase
{
    #[DataProvider('validScopes')]
    public function test_it_accepts_only_canonical_scope_shapes(string $type, ?int $id): void
    {
        $scope = new AssignmentScope($type, $id);

        self::assertSame($type, $scope->type);
        self::assertSame($id, $scope->id);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function validScopes(): iterable
    {
        yield 'all' => ['all', null];
        yield 'own' => ['own', null];
        yield 'organization' => ['organization', 11];
        yield 'department' => ['department', 12];
        yield 'project' => ['project', 13];
        yield 'program' => ['program', 14];
        yield 'portfolio' => ['portfolio', 15];
        yield 'kpi' => ['kpi', 16];
        yield 'meeting' => ['meeting', 17];
        yield 'survey' => ['survey', 18];
    }

    #[DataProvider('invalidScopes')]
    public function test_it_rejects_unknown_or_structurally_invalid_scopes(string $type, ?int $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AssignmentScope($type, $id);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function invalidScopes(): iterable
    {
        yield 'unknown' => ['unknown', 1];
        yield 'unresolvable cluster' => ['cluster', 1];
        yield 'unresolvable hospital' => ['hospital', 1];
        yield 'unresolvable team' => ['team', 1];
        yield 'all with id' => ['all', 1];
        yield 'own with id' => ['own', 1];
        yield 'organization without id' => ['organization', null];
        yield 'non-positive id' => ['department', 0];
    }

    #[DataProvider('roleScopeCompatibility')]
    public function test_role_scope_compatibility_is_exact_and_own_is_explicit(
        string $roleScope,
        string $assignmentScope,
        bool $compatible,
    ): void {
        $scope = new AssignmentScope(
            $assignmentScope,
            in_array($assignmentScope, ['all', 'own'], true) ? null : 42,
        );

        self::assertSame($compatible, $scope->isCompatibleWithRoleScope($roleScope));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function roleScopeCompatibility(): iterable
    {
        yield 'all is exact' => ['all', 'all', true];
        yield 'all role cannot be narrowed' => ['all', 'organization', false];
        yield 'organization is exact' => ['organization', 'organization', true];
        yield 'organization role is not an own role' => ['organization', 'own', false];
        yield 'own is supported only when declared' => ['own', 'own', true];
        yield 'project role cannot be assigned to department' => ['project', 'department', false];
    }

    public function test_semantic_key_is_stable_and_ignores_write_metadata(): void
    {
        $scope = new AssignmentScope('department', 42, true);

        self::assertSame('department:42', $scope->semanticKey());
    }
}
