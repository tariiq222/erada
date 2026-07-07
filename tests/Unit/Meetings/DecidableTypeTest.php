<?php

namespace Tests\Unit\Meetings;

use App\Modules\Meetings\Support\DecidableType;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecidableTypeTest extends TestCase
{
    public function test_aliases_lists_all_supported_link_types(): void
    {
        $this->assertSame(
            ['project', 'portfolio', 'program', 'risk'],
            DecidableType::aliases()
        );
    }

    public function test_class_for_resolves_each_alias_to_its_model(): void
    {
        $this->assertSame(Project::class, DecidableType::classFor('project'));
        $this->assertSame(Portfolio::class, DecidableType::classFor('portfolio'));
        $this->assertSame(Program::class, DecidableType::classFor('program'));
        $this->assertSame(Risk::class, DecidableType::classFor('risk'));
    }

    public function test_class_for_throws_on_unknown_alias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecidableType::classFor('committee');
    }

    public function test_alias_for_maps_a_class_back_to_its_alias(): void
    {
        $this->assertSame('risk', DecidableType::aliasFor(Risk::class));
        $this->assertNull(DecidableType::aliasFor('App\\Some\\Unmapped\\Model'));
    }
}
