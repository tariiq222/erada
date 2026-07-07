<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable'); // يمكن ربطها بأي موديل
            $table->string('name'); // اسم الملف
            $table->string('file_path'); // مسار الملف
            $table->string('file_type')->nullable(); // نوع الملف
            $table->unsignedBigInteger('file_size')->nullable(); // حجم الملف
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
