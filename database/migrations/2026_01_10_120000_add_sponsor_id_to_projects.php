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
        // إضافة حقل راعي/مالك المشروع باستخدام SQL مباشر لتجنب مشاكل SQLite
        if (! Schema::hasColumn('projects', 'sponsor_id')) {
            DB::statement('ALTER TABLE projects ADD COLUMN sponsor_id INTEGER REFERENCES users(id) ON DELETE SET NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('projects', 'sponsor_id')) {
            // SQLite لا يدعم DROP COLUMN بسهولة، لكن هذا للـ rollback
            DB::statement('ALTER TABLE projects DROP COLUMN sponsor_id');
        }
    }
};
