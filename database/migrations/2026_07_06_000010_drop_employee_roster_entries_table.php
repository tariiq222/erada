<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `employee_roster_entries` table.
 *
 * Background: the table was added to support an invite + admin-approval
 * registration flow (admin imports a roster of pre-provisioned employees,
 * each row gets an invite_token, claimed on registration). The simplified
 * registration flow (single-step, no invite, no admin approval) makes the
 * roster obsolete — the Model, services, controllers, and routes that
 * touched it have already been deleted in the same change set.
 *
 * This migration is the last piece: it removes the table itself, with
 * a `down()` that recreates the original columns so the operation is
 * reversible. No production data is expected to exist (the table was
 * only ever populated by the now-deleted `RosterImportService`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_roster_entries');
    }

    public function down(): void
    {
        Schema::create('employee_roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('full_name')->nullable();
            $table->string('employee_number', 50)->nullable();
            $table->text('national_id_hash')->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->json('default_roles')->nullable();
            $table->string('invite_token_hash')->nullable();
            $table->timestamp('invite_token_expires_at')->nullable();
            $table->string('access_profile', 64)->nullable();
            $table->string('status')->default('available');
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
