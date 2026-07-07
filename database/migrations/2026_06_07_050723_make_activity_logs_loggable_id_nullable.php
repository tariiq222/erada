<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Allow activity log entries that are not tied to a specific model
        // (e.g. failed login attempts where no user record exists yet).
        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id SET NOT NULL');
    }
};
