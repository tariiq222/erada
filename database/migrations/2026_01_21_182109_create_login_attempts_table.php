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
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('ip_address', 45)->index();
            $table->string('user_agent')->nullable();
            $table->boolean('successful')->default(false);
            $table->timestamp('attempted_at')->useCurrent();

            // فهرس مركب للبحث السريع
            $table->index(['email', 'successful', 'attempted_at']);
            $table->index(['ip_address', 'attempted_at']);
        });

        // إضافة حقول قفل الحساب لجدول المستخدمين
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locked_until')->nullable()->after('is_active');
            $table->integer('failed_login_attempts')->default(0)->after('locked_until');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('last_failed_login_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'locked_until',
                'failed_login_attempts',
                'last_failed_login_at',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
