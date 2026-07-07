<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_RISK_TYPES = [
        'operational' => 'تشغيلي',
        'clinical' => 'سريري',
        'financial' => 'مالي',
        'technical' => 'تقني',
        'compliance' => 'امتثال',
        'reputational' => 'سمعة',
    ];

    private const DEFAULT_IMPACT_LABELS = [
        1 => 'منخفض جداً',
        2 => 'منخفض',
        3 => 'متوسط',
        4 => 'مرتفع',
        5 => 'مرتفع جداً',
    ];

    public function up(): void
    {
        Schema::create('risk_types', function (Blueprint $table) {
            $table->id();
            $table->string('value', 30)->unique();
            $table->string('label', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('risk_impact_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('value')->unique();
            $table->string('label', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        $riskTypes = [];
        $sortOrder = 1;
        foreach (self::LEGACY_RISK_TYPES as $value => $label) {
            $riskTypes[] = [
                'value' => $value,
                'label' => $label,
                'is_active' => true,
                'sort_order' => $sortOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('risk_types')->insert($riskTypes);

        DB::table('risk_impact_types')->insert(
            collect(self::DEFAULT_IMPACT_LABELS)->map(fn (string $label, int $value) => [
                'value' => $value,
                'label' => $label,
                'is_active' => true,
                'sort_order' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );

        DB::statement('ALTER TABLE risks DROP CONSTRAINT IF EXISTS risks_type_check');
    }

    public function down(): void
    {
        if (Schema::hasTable('risks')) {
            $hasCustomTypes = DB::table('risks')
                ->whereNotIn('type', array_keys(self::LEGACY_RISK_TYPES))
                ->exists();

            if (! $hasCustomTypes) {
                DB::statement('ALTER TABLE risks DROP CONSTRAINT IF EXISTS risks_type_check');
                DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_type_check CHECK (type IN ('operational','clinical','financial','technical','compliance','reputational'))");
            }
        }

        Schema::dropIfExists('risk_impact_types');
        Schema::dropIfExists('risk_types');
    }
};
