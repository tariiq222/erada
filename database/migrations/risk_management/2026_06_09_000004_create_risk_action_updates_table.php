<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_action_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_action_id')->constrained('risk_actions')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('progress_pct')->nullable();
            $table->string('status', 20)->nullable();
            $table->text('notes');
            $table->timestamps();

            $table->index(['risk_action_id', 'created_at'], 'risk_action_updates_action_created_idx');
            $table->index(['organization_id', 'created_at'], 'risk_action_updates_org_created_idx');
        });

        DB::statement("ALTER TABLE risk_action_updates ADD CONSTRAINT risk_action_updates_status_check CHECK (status IN ('pending','in_progress','completed','blocked','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_action_updates');
    }
};
