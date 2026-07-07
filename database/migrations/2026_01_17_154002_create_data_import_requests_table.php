<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_import_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('data_mapping_templates')->nullOnDelete();

            $table->string('target_table', 100);
            $table->unsignedBigInteger('target_id')->nullable(); // للتحديث
            $table->string('operation', 20)->default('create'); // create, update, upsert

            $table->json('payload'); // البيانات المراد إدخالها
            $table->json('diff')->nullable(); // الفروقات (للتحديث)

            $table->string('upsert_key_field', 100)->nullable();
            $table->string('upsert_key_value')->nullable();

            $table->string('status', 20)->default('pending'); // pending, approved, rejected, applied, failed
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('requested_at');

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_id')->nullable(); // ID السجل المنشأ/المحدث
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_requests');
    }
};
