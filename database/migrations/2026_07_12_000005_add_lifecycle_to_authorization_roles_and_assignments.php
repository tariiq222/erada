<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorization_roles', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('authorization_role_assignments', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('authorization_role_assignments', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('authorization_roles', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
