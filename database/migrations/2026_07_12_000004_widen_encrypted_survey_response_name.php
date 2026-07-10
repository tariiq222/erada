<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->text('respondent_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Encrypted values can exceed 255 characters, so narrowing this column
        // would either fail or silently destroy respondent identity data.
    }
};
