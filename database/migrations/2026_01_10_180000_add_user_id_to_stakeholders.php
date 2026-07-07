<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // إضافة حقل user_id لربط صاحب المصلحة بمستخدم في النظام
        if (! Schema::hasColumn('stakeholders', 'user_id')) {
            DB::statement('ALTER TABLE stakeholders ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('stakeholders', 'user_id')) {
            DB::statement('ALTER TABLE stakeholders DROP COLUMN user_id');
        }
    }
};
