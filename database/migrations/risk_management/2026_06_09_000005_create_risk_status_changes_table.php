<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['risk_id', 'created_at'], 'risk_status_changes_risk_created_idx');
            $table->index(['organization_id', 'to_status'], 'risk_status_changes_org_status_idx');
        });

        DB::statement("ALTER TABLE risk_status_changes ADD CONSTRAINT risk_status_changes_from_status_check CHECK (from_status IN ('open','treating','closed','accepted'))");
        DB::statement("ALTER TABLE risk_status_changes ADD CONSTRAINT risk_status_changes_to_status_check CHECK (to_status IN ('open','treating','closed','accepted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_status_changes');
    }
};
