<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained('kpis')->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            $table->date('measurement_date');
            $table->text('notes')->nullable();
            $table->string('evidence_url', 2048)->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'measurement_date']);
            $table->index(['kpi_id', 'measurement_date']);
            $table->index('recorded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_measurements');
    }
};
