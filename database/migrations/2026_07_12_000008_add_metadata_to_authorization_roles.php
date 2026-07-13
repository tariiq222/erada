<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorization_roles', function (Blueprint $table): void {
            $table->string('label_ar')->nullable();
            $table->string('label_en')->nullable();
            $table->string('scope_type')->default('organization');
            $table->boolean('is_system')->default(false);
        });

        DB::table('authorization_roles')->update([
            'label_ar' => DB::raw('label'),
            'label_en' => DB::raw('label'),
        ]);

        DB::table('authorization_roles')
            ->whereIn('name', ['super_admin', 'admin', 'viewer'])
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('authorization_roles', function (Blueprint $table): void {
            $table->dropColumn(['label_ar', 'label_en', 'scope_type', 'is_system']);
        });
    }
};
