<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop & re-add to avoid needing doctrine/dbal for ->change() on Postgres TEXT.
        Schema::table('ovr_incident_reports', fn (Blueprint $t) => $t->dropColumn('patient_file_number'));
        Schema::table('ovr_incident_reports', fn (Blueprint $t) => $t->text('patient_file_number')->nullable()->after('patient_name'));
    }

    public function down(): void
    {
        Schema::table('ovr_incident_reports', fn (Blueprint $t) => $t->dropColumn('patient_file_number'));
        Schema::table('ovr_incident_reports', fn (Blueprint $t) => $t->string('patient_file_number', 100)->nullable()->after('patient_name'));
    }
};
