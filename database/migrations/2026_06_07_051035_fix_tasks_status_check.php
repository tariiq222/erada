<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status::text = ANY (ARRAY['todo'::character varying, 'in_progress'::character varying, 'in_review'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'on_hold'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status::text = ANY (ARRAY['todo'::character varying, 'in_progress'::character varying, 'in_review'::character varying, 'completed'::character varying]::text[]))");
    }
};
