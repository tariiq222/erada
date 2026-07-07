<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as SpatiePermission;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['view-meetings', 'manage-meetings', 'record-decisions'] as $name) {
            SpatiePermission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    public function down(): void
    {
        SpatiePermission::whereIn('name', ['view-meetings', 'manage-meetings', 'record-decisions'])->delete();
    }
};
