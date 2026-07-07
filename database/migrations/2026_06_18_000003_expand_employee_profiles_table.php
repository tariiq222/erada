<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_profiles', 'ministry_hire_date')) {
                $table->date('ministry_hire_date')->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'contract_type')) {
                $table->string('contract_type', 30)->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'social_insurance_number')) {
                $table->string('social_insurance_number', 50)->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'specialization')) {
                $table->string('specialization')->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'current_work_field')) {
                $table->string('current_work_field')->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'fingerprint_number')) {
                $table->string('fingerprint_number', 50)->nullable();
            }

            if (! Schema::hasColumn('employee_profiles', 'staff_category')) {
                $table->string('staff_category', 20)->nullable();
                $table->index('staff_category');
            }
        });

        // The original column is a plain string, but we now want to enforce the 4-state set
        // at the DB level too (defence-in-depth alongside the Eloquent STATUSES constant).
        $existing = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'employee_profiles'::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) LIKE '%employment_status%'"
        );

        if ($existing !== null) {
            DB::statement('ALTER TABLE employee_profiles DROP CONSTRAINT "'.$existing->conname.'"');
        }

        DB::statement(
            "ALTER TABLE employee_profiles
             ADD CONSTRAINT employee_profiles_employment_status_check
             CHECK (employment_status IN ('active','suspended','terminated','on_leave'))"
        );
    }

    public function down(): void
    {
        $existing = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'employee_profiles'::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) LIKE '%employment_status%'"
        );

        if ($existing !== null) {
            DB::statement('ALTER TABLE employee_profiles DROP CONSTRAINT "'.$existing->conname.'"');
        }

        DB::statement(
            "ALTER TABLE employee_profiles
             ADD CONSTRAINT employee_profiles_employment_status_check
             CHECK (employment_status IN ('active','suspended','terminated'))"
        );

        Schema::table('employee_profiles', function (Blueprint $table) {
            $columns = [
                'ministry_hire_date',
                'contract_type',
                'social_insurance_number',
                'specialization',
                'current_work_field',
                'fingerprint_number',
                'staff_category',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('employee_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
