<?php

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\ReferenceNumberGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $gen = app(ReferenceNumberGenerator::class);

        Meeting::withTrashed()->whereNull('reference_number')->orderBy('id')->chunkById(100, function ($rows) use ($gen) {
            foreach ($rows as $row) {
                $row->reference_number = $gen->generate('MTG', substr((string) $row->created_at, 0, 4));
                $row->saveQuietly();
            }
        });

        Recommendation::withTrashed()->whereNull('reference_number')->orderBy('id')->chunkById(100, function ($rows) use ($gen) {
            foreach ($rows as $row) {
                $row->reference_number = $gen->generate('REC', substr((string) $row->created_at, 0, 4));
                $row->saveQuietly();
            }
        });

        // Phase R1 / Direction B: the `decisions` table is dropped by
        // migration 2026_07_06_300003, so the historical decision
        // reference-number backfill is moot — guarded behind a table
        // existence check so a fresh migrate doesn't try to autoload a
        // class that no longer exists.
        if (Schema::hasTable('decisions')) {
            DB::table('decisions')
                ->whereNull('reference_number')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($gen) {
                    foreach ($rows as $row) {
                        DB::table('decisions')
                            ->where('id', $row->id)
                            ->update(['reference_number' => $gen->generate('DEC', substr((string) $row->created_at, 0, 4))]);
                    }
                });
        }
    }

    public function down(): void
    {
        DB::statement('UPDATE meetings SET reference_number = NULL');
        DB::statement('UPDATE recommendations SET reference_number = NULL');
        if (Schema::hasTable('decisions')) {
            DB::statement('UPDATE decisions SET reference_number = NULL');
        }
    }
};
