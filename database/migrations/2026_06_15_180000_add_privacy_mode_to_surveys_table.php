<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            if (! Schema::hasColumn('surveys', 'privacy_mode')) {
                $table->string('privacy_mode', 20)->default('identified')->after('requires_auth');
            }
        });

        DB::statement('ALTER TABLE surveys DROP CONSTRAINT IF EXISTS surveys_privacy_mode_check');
        DB::statement("ALTER TABLE surveys ADD CONSTRAINT surveys_privacy_mode_check CHECK (privacy_mode IN ('identified', 'confidential', 'anonymous'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE surveys DROP CONSTRAINT IF EXISTS surveys_privacy_mode_check');

        Schema::table('surveys', function (Blueprint $table) {
            if (Schema::hasColumn('surveys', 'privacy_mode')) {
                $table->dropColumn('privacy_mode');
            }
        });
    }
};
