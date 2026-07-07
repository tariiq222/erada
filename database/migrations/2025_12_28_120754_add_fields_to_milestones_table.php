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
        Schema::table('milestones', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('description'); // تاريخ البداية
            $table->decimal('progress', 5, 2)->default(0)->after('status'); // نسبة الإنجاز
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'progress']);
        });
    }
};
