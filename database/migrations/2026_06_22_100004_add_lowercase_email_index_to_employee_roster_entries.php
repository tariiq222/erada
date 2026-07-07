<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Functional index — supports case-insensitive roster lookups in
        // RegistrationController::start and elsewhere. Prevents a timing channel
        // that would otherwise scale with table size.
        DB::statement(
            'CREATE INDEX employee_roster_entries_email_lower_idx ON employee_roster_entries (LOWER(email))'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_roster_entries_email_lower_idx');
    }
};
