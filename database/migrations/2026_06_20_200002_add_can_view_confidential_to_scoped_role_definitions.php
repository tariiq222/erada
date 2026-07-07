<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('scoped_role_definitions', 'can_view_confidential')) {
            Schema::table('scoped_role_definitions', function (Blueprint $table) {
                $table->boolean('can_view_confidential')->default(false)->after('can_view_all');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scoped_role_definitions', 'can_view_confidential')) {
            Schema::table('scoped_role_definitions', function (Blueprint $table) {
                $table->dropColumn('can_view_confidential');
            });
        }
    }
};
