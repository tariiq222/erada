<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report participants: any employee (from any department) invited to an
 * incident report. A participant gains visibility of the report via
 * IncidentReport::scopeVisibleTo (participants OR branch).
 *
 * The incident report primary key is a UUID (char(36)); the FK column matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ovr_incident_participants')) {
            return;
        }

        Schema::create('ovr_incident_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('incident_report_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('incident_report_id')
                ->references('id')
                ->on('ovr_incident_reports')
                ->cascadeOnDelete();

            $table->unique(['incident_report_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovr_incident_participants');
    }
};
