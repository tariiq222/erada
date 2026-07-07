<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value settings for the OVR module (mirrors project_settings / risk_settings).
 * Holds the configurable "governing department for OVR" among future preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ovr_settings')) {
            return;
        }

        Schema::create('ovr_settings', function (Blueprint $table) {
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
        Schema::dropIfExists('ovr_settings');
    }
};
