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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            // Polymorphic relation (objective, initiative, project)
            $table->morphs('reviewable');

            $table->enum('type', ['monthly', 'quarterly', 'annual', 'adhoc'])->default('quarterly');

            // PDCA Cycle
            $table->enum('pdca_phase', ['plan', 'do', 'check', 'act'])->default('check');

            $table->date('review_date');
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('progress_snapshot', 5, 2)->nullable();
            $table->enum('overall_status', ['on_track', 'at_risk', 'off_track', 'completed'])->default('on_track');

            $table->text('achievements')->nullable();
            $table->text('challenges')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->text('next_steps')->nullable();
            $table->text('recommendations')->nullable();

            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('attendees')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reviewable_type', 'reviewable_id', 'deleted_at'], 'reviews_reviewable_index');
            $table->index(['type', 'pdca_phase']);
            $table->index('review_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
