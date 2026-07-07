<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing foreign key if present (morphs don't create FK by default, but indexes yes)
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_loggable_type_loggable_id_index');
        });

        // PostgreSQL: change column type using raw SQL
        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id TYPE VARCHAR(255) USING loggable_id::VARCHAR');

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['loggable_type', 'loggable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_loggable_type_loggable_id_index');
        });

        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id TYPE BIGINT USING loggable_id::BIGINT');

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['loggable_id', 'loggable_type']);
        });
    }
};
