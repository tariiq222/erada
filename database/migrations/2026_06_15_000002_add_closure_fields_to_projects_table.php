<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('lessons_learned')->nullable()->after('current_pdca_phase');
            $table->text('outcome_summary')->nullable()->after('lessons_learned');
            $table->text('sustainability_plan')->nullable()->after('outcome_summary');
            $table->decimal('achievement_percentage', 5, 2)->nullable()->after('sustainability_plan');
            $table->string('achievement_status')->nullable()->after('achievement_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'lessons_learned',
                'outcome_summary',
                'sustainability_plan',
                'achievement_percentage',
                'achievement_status',
            ]);
        });
    }
};
