<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_personal_info', 'full_name_english')) {
            Schema::table('employee_personal_info', function (Blueprint $table) {
                $table->string('full_name_english')->nullable()->after('full_name_arabic');
            });
        }

        $rows = DB::table('employee_personal_info')
            ->whereNotNull('first_name')
            ->orWhereNotNull('last_name')
            ->get();

        foreach ($rows as $row) {
            $combined = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
            if ($combined !== '') {
                DB::table('employee_personal_info')
                    ->where('id', $row->id)
                    ->update(['full_name_english' => $combined]);
            }
        }

        Schema::table('employee_personal_info', function (Blueprint $table) {
            if (Schema::hasColumn('employee_personal_info', 'first_name')) {
                $table->dropColumn('first_name');
            }
            if (Schema::hasColumn('employee_personal_info', 'last_name')) {
                $table->dropColumn('last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_personal_info', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_personal_info', 'first_name')) {
                $table->string('first_name')->nullable()->after('full_name_arabic');
            }
            if (! Schema::hasColumn('employee_personal_info', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
        });

        $rows = DB::table('employee_personal_info')
            ->whereNotNull('full_name_english')
            ->get();

        foreach ($rows as $row) {
            $parts = explode(' ', (string) $row->full_name_english, 2);
            DB::table('employee_personal_info')
                ->where('id', $row->id)
                ->update([
                    'first_name' => $parts[0] ?? null,
                    'last_name' => $parts[1] ?? null,
                ]);
        }

        Schema::table('employee_personal_info', function (Blueprint $table) {
            if (Schema::hasColumn('employee_personal_info', 'full_name_english')) {
                $table->dropColumn('full_name_english');
            }
        });
    }
};
