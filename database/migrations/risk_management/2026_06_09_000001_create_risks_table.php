<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('title');
            $table->date('discovery_date');
            $table->string('type', 30);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('initial_likelihood');
            $table->unsignedTinyInteger('initial_impact');
            $table->unsignedTinyInteger('current_likelihood');
            $table->unsignedTinyInteger('current_impact');
            $table->unsignedTinyInteger('current_score');
            $table->string('current_level', 20);
            $table->string('status', 20)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('stakeholder_ids')->nullable();
            $table->text('preventive_measures')->nullable();
            $table->date('target_close_date')->nullable();
            $table->string('response_type', 20);
            $table->nullableMorphs('riskable');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id', 'risks_org_id_idx');
            $table->index(['organization_id', 'status'], 'risks_org_status_idx');
            $table->index(['organization_id', 'current_level'], 'risks_org_level_idx');
            $table->index(['organization_id', 'current_score'], 'risks_org_score_idx');
            $table->index(['organization_id', 'department_id'], 'risks_org_department_idx');
            $table->index(['organization_id', 'owner_id'], 'risks_org_owner_idx');
            $table->index(['current_likelihood', 'current_impact'], 'risks_matrix_idx');
            $table->index('target_close_date', 'risks_target_close_date_idx');
        });

        DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_type_check CHECK (type IN ('operational','clinical','financial','technical','compliance','reputational'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_status_check CHECK (status IN ('open','treating','closed','accepted'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_current_level_check CHECK (current_level IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_response_type_check CHECK (response_type IN ('avoid','mitigate','transfer','accept'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
