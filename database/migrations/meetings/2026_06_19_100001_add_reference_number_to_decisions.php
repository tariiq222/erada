<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('decisions', 'reference_number')) {
            Schema::table('decisions', function (Blueprint $t) {
                $t->string('reference_number')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('decisions', 'reference_number')) {
            Schema::table('decisions', fn (Blueprint $t) => $t->dropColumn('reference_number'));
        }
    }
};
