<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CanonicalProjectAuthorizationCallersTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function productionCallerProvider(): array
    {
        return [
            'task controller' => ['app/Modules/Tasks/Http/Controllers/TaskController.php'],
            'comment controller' => ['app/Modules/Shared/Http/Controllers/CommentController.php'],
            'comment policy' => ['app/Modules/Shared/Policies/CommentPolicy.php'],
            'attachment policy' => ['app/Modules/Shared/Policies/AttachmentPolicy.php'],
        ];
    }

    #[DataProvider('productionCallerProvider')]
    public function test_project_authorization_callers_use_the_canonical_engine(string $path): void
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/->(?:hasRoleInProject|isProjectAdmin)\s*\(/',
            $source,
            "Legacy project-role authorization caller remains in {$path}."
        );
    }
}
