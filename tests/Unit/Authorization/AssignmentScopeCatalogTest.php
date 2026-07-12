<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AssignmentScopeCatalogTest extends TestCase
{
    #[Test]
    public function catalog_is_derived_from_the_canonical_assignment_scope_types(): void
    {
        $catalog = AssignmentScope::catalog();

        $this->assertSame(AssignmentScope::TYPES, array_column($catalog, 'key'));
        $this->assertSame('none', $catalog[0]['target_requirement']);
        $this->assertSame('none', collect($catalog)->firstWhere('key', 'own')['target_requirement']);
        $this->assertSame('required', collect($catalog)->firstWhere('key', 'project')['target_requirement']);
        $this->assertSame('المشروع', collect($catalog)->firstWhere('key', 'project')['label_ar']);
        $this->assertSame('Project', collect($catalog)->firstWhere('key', 'project')['label_en']);
    }
}
