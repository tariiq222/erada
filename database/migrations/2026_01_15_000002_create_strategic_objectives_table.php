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
        Schema::create('strategic_objectives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('direction_id')->constrained('strategic_directions')->cascadeOnDelete();

            // BSC Perspectives
            $table->enum('bsc_perspective', [
                'financial',
                'customer',
                'internal_process',
                'learning_growth',
            ]);

            $table->decimal('target_value', 15, 2)->nullable();
            $table->string('measurement_unit', 50)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->decimal('baseline_value', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->unsignedTinyInteger('order')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['direction_id', 'status', 'deleted_at']);
            $table->index(['bsc_perspective', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategic_objectives');
    }
};
