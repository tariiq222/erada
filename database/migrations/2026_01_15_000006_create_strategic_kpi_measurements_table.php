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
        Schema::create('strategic_kpi_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id')->constrained('strategic_kpis')->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            $table->date('measurement_date');
            $table->text('notes')->nullable();
            $table->string('evidence_url')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kpi_id', 'measurement_date']);
            $table->index(['kpi_id', 'measurement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategic_kpi_measurements');
    }
};
