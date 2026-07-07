<?php

namespace Tests\Unit\Meetings;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\ReferenceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_returns_first_sequence_for_empty_table(): void
    {
        $gen = app(ReferenceNumberGenerator::class);
        $this->assertSame('MTG-2026-0001', $gen->generate('MTG', '2026'));
    }

    public function test_generate_increments_sequence(): void
    {
        // Seed two rows so the generator sees 0001 and 0002 already taken.
        Meeting::factory()->create(['reference_number' => 'MTG-2026-0001']);
        Meeting::factory()->create(['reference_number' => 'MTG-2026-0002']);

        $gen = app(ReferenceNumberGenerator::class);
        $this->assertSame('MTG-2026-0003', $gen->generate('MTG', '2026'));
    }

    public function test_generate_resets_sequence_per_year(): void
    {
        Meeting::factory()->create(['reference_number' => 'MTG-2025-0001']);

        $gen = app(ReferenceNumberGenerator::class);
        $this->assertSame('MTG-2026-0001', $gen->generate('MTG', '2026'));
    }

    public function test_generate_pads_to_four_digits(): void
    {
        // One row with reference_number 'MTG-2026-9999' is sufficient —
        // the generator reads MAX(SPLIT_PART(...)) so a single row with that
        // value drives MAX = 9999, yielding 10000 (str_pad length 4 does not
        // truncate values that exceed it). Creating 9999 identical rows was
        // the original approach but violates the new partial unique index.
        Meeting::factory()->create([
            'organization_id' => 1,
            'reference_number' => 'MTG-2026-9999',
        ]);
        $gen = app(ReferenceNumberGenerator::class);
        $this->assertSame('MTG-2026-10000', $gen->generate('MTG', '2026'));
    }
}
