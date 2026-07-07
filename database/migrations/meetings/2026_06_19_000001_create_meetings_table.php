<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('location')->nullable();
            $table->string('virtual_link', 2048)->nullable();
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id', 'meetings_org_id_idx');
            $table->index(['organization_id', 'status'], 'meetings_org_status_idx');
            $table->index(['organization_id', 'scheduled_at'], 'meetings_org_scheduled_at_idx');
            $table->index('reminder_sent_at', 'meetings_reminder_sent_at_idx');
        });

        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_status_check CHECK (status IN ('scheduled','in_progress','completed','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
