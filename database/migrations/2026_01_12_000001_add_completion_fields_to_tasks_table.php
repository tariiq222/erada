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
        // حقول الإكمال - التحقق من عدم وجودها أولاً
        if (! Schema::hasColumn('tasks', 'challenges')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->text('challenges')->nullable()->after('completed_date');
            });
        }
        if (! Schema::hasColumn('tasks', 'lessons_learned')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->text('lessons_learned')->nullable()->after('challenges');
            });
        }
        if (! Schema::hasColumn('tasks', 'status_comment')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->text('status_comment')->nullable()->after('lessons_learned');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['challenges', 'lessons_learned', 'status_comment']);
        });
    }
};
