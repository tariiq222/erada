<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تحويل cluster_settings/organizations إلى system_settings
     */
    public function up(): void
    {
        // إذا كان جدول system_settings موجود، لا نفعل شيء
        if (Schema::hasTable('system_settings')) {
            // تأكد من وجود الأعمدة المطلوبة
            if (! Schema::hasColumn('system_settings', 'region')) {
                Schema::table('system_settings', function (Blueprint $table) {
                    $table->string('region')->nullable()->after('code');
                    $table->string('city')->nullable()->after('region');
                    $table->string('address')->nullable()->after('city');
                });
            }

            return;
        }

        // إذا كان cluster_settings موجود، نعيد تسميته
        if (Schema::hasTable('cluster_settings')) {
            Schema::rename('cluster_settings', 'system_settings');

            // تأكد من وجود الأعمدة المطلوبة
            if (! Schema::hasColumn('system_settings', 'region')) {
                Schema::table('system_settings', function (Blueprint $table) {
                    $table->string('region')->nullable()->after('code');
                    $table->string('city')->nullable()->after('region');
                    $table->string('address')->nullable()->after('city');
                });
            }

            return;
        }

        // إذا كان organizations موجود، ننقل البيانات منه
        if (Schema::hasTable('organizations')) {
            // إنشاء جدول system_settings
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('name_en')->nullable();
                $table->string('code')->unique()->nullable();
                $table->string('region')->nullable();
                $table->string('city')->nullable();
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('website')->nullable();
                $table->string('logo')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });

            // نقل أول سجل من organizations
            $org = DB::table('organizations')->first();
            if ($org) {
                DB::table('system_settings')->insert([
                    'name' => $org->name ?? 'نظام إدارة المشاريع',
                    'name_en' => $org->name_en ?? 'Project Management System',
                    'code' => $org->code ?? null,
                    'phone' => $org->phone ?? null,
                    'email' => $org->email ?? null,
                    'logo' => $org->logo ?? null,
                    'settings' => $org->settings ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // إنشاء سجل افتراضي
                DB::table('system_settings')->insert([
                    'name' => 'نظام إدارة المشاريع',
                    'name_en' => 'Project Management System',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        // إذا لم يوجد أي جدول، ننشئ system_settings جديد
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('code')->unique()->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // إنشاء سجل افتراضي
        DB::table('system_settings')->insert([
            'name' => 'نظام إدارة المشاريع',
            'name_en' => 'Project Management System',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا نحذف لأن هذا قد يسبب فقدان البيانات
    }
};
