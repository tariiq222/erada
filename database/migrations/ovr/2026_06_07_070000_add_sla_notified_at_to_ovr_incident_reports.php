<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ovr_incident_reports', function (Blueprint $table) {
            $table->timestamp('sla_notified_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('ovr_incident_reports', function (Blueprint $table) {
            $table->dropColumn('sla_notified_at');
        });
    }
};
