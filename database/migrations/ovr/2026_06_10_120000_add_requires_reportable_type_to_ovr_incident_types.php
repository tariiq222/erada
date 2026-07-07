<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ovr_incident_types', function (Blueprint $table) {
            $table->boolean('requires_reportable_type')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ovr_incident_types', function (Blueprint $table) {
            $table->dropColumn('requires_reportable_type');
        });
    }
};
