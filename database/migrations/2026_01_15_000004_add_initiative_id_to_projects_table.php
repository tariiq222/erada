<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // تخطي إذا كان العمود موجوداً مسبقاً
        if (Schema::hasColumn('projects', 'initiative_id') || Schema::hasColumn('projects', 'program_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        Schema::table('projects', function (Blueprint $table) use ($isSqlite) {
            if ($isSqlite) {
                // SQLite: استخدام unsignedBigInteger بدون FK constraints
                $table->unsignedBigInteger('initiative_id')->nullable()->after('department_id');
            } else {
                // MySQL/PostgreSQL
                $table->foreignId('initiative_id')
                    ->nullable()
                    ->after('department_id')
                    ->constrained('initiatives')
                    ->nullOnDelete();
            }

            $table->index(['initiative_id', 'status'], 'projects_initiative_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'initiative_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        Schema::table('projects', function (Blueprint $table) use ($isSqlite) {
            if (! $isSqlite) {
                $table->dropForeign(['initiative_id']);
            }
            $table->dropIndex('projects_initiative_status_idx');
            $table->dropColumn('initiative_id');
        });
    }
};
