<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lifecycle for governed registration. Existing users backfill to 'active'.
            $table->string('registration_status', 32)->default('active')->after('is_active');
            $table->timestamp('registration_approved_at')->nullable()->after('registration_status');
            $table->foreignId('registration_approved_by')->nullable()->after('registration_approved_at')
                ->constrained('users')->nullOnDelete();
            $table->index('registration_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_approved_by');
            $table->dropColumn(['registration_status', 'registration_approved_at']);
        });
    }
};
