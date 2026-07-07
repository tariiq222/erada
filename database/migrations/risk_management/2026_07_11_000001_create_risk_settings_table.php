<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value settings for the Risk module (mirrors project_settings). Holds the
 * configurable "governing department for risks" among future risk preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('risk_settings')) {
            return;
        }

        Schema::create('risk_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, json, boolean, integer
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_settings');
    }
};
