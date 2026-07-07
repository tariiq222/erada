<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ovr_incident_types', function (Blueprint $table) {
            // Nullable so the seeded hospital "global" library survives for super_admin read.
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index(['organization_id', 'is_active']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::table('ovr_reportable_types', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index(['organization_id', 'incident_type_id']);
            $table->unique(['organization_id', 'incident_type_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('ovr_reportable_types', fn (Blueprint $t) => $t->dropUnique(['organization_id', 'incident_type_id', 'name']));
        Schema::table('ovr_reportable_types', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('ovr_incident_types', fn (Blueprint $t) => $t->dropUnique(['organization_id', 'name']));
        Schema::table('ovr_incident_types', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
    }
};
