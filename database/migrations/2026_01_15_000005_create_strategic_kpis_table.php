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
        Schema::create('strategic_kpis', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('measurement_method')->nullable();

            // Polymorphic relation (can belong to objective or initiative)
            $table->morphs('measurable');

            $table->decimal('baseline', 15, 2)->nullable();
            $table->decimal('target', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->string('unit', 50)->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->enum('trend', ['up_good', 'down_good', 'stable'])->default('up_good');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['measurable_type', 'measurable_id', 'deleted_at'], 'strategic_kpis_measurable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategic_kpis');
    }
};
