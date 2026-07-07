<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->unsignedSmallInteger('default_duration_minutes')->default(60);
            $table->unsignedSmallInteger('reminder_window_hours')->default(24);
            $table->json('attendee_roles')->nullable();
            $table->foreignId('default_category_id')->nullable()->constrained('meeting_categories')->nullOnDelete();
            $table->boolean('agenda_request_enabled')->default(true);
            $table->unsignedSmallInteger('agenda_request_lead_hours')->default(48);
            $table->unsignedSmallInteger('decision_pending_expiry_days')->default(30);
            $table->unsignedSmallInteger('recommendation_overdue_grace_days')->default(0);
            $table->timestamps();

            $table->unique('organization_id', 'meeting_settings_org_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_settings');
    }
};
