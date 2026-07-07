<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // اسم صاحب المصلحة
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('organization')->nullable(); // الجهة التابع لها
            $table->enum('role', ['sponsor', 'client', 'team_member', 'consultant', 'vendor', 'other'])
                ->default('other');
            $table->enum('influence', ['low', 'medium', 'high'])->default('medium'); // مستوى التأثير
            $table->enum('interest', ['low', 'medium', 'high'])->default('medium'); // مستوى الاهتمام
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stakeholders');
    }
};
