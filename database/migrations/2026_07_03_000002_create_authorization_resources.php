<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_resources`.
 *
 * A resource is the canonical FQCN of a model class the authorization engine
 * is asked about, e.g. `App\Modules\Projects\Models\Project`. The `key` column
 * is unique so seeded lookup is a single index hit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_resources')) {
            return;
        }

        Schema::create('authorization_resources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_resources');
    }
};
