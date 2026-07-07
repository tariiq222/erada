<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovr_reportable_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_type_id')->constrained('ovr_incident_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovr_reportable_types');
    }
};
