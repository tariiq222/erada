<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('canonical_id')->nullable()->constrained('surveys')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('initial'); // initial, periodic
            $table->string('category', 50)->nullable(); // kpi, satisfaction, needs, report

            $table->string('status', 20)->default('draft'); // draft, published, closed, archived
            $table->boolean('is_public')->default(true);
            $table->boolean('requires_auth')->default(false);
            $table->boolean('accepting_responses')->default(true);
            $table->boolean('allow_multiple_responses')->default(false);
            $table->boolean('allow_edit_response')->default(false);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();

            $table->text('consent_text')->nullable();
            $table->boolean('consent_required')->default(false);
            $table->text('welcome_message')->nullable();
            $table->text('thank_you_message')->nullable();

            $table->json('settings')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'revision']);
            $table->index(['organization_id', 'status']);
            $table->index('canonical_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
