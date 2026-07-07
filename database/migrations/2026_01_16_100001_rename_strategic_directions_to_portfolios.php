<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * هذا الـ Migration يقوم بـ:
     * 1. إعادة تسمية الجدول من strategic_directions إلى portfolios
     * 2. إضافة الحقول الجديدة للمحفظة
     * 3. تحديث العلاقات في جدول initiatives
     */
    public function up(): void
    {
        // 1. إعادة تسمية الجدول الرئيسي (تخطي إذا تم التحويل مسبقاً)
        if (Schema::hasTable('strategic_directions') && ! Schema::hasTable('portfolios')) {
            Schema::rename('strategic_directions', 'portfolios');
        }

        // 2. إضافة الحقول الجديدة (تخطي الموجودة)
        if (Schema::hasTable('portfolios')) {
            Schema::table('portfolios', function (Blueprint $table) {
                // مالك المحفظة (القرار التنفيذي)
                if (! Schema::hasColumn('portfolios', 'portfolio_owner_id')) {
                    $table->foreignId('portfolio_owner_id')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                // ترتيب الأولوية
                if (! Schema::hasColumn('portfolios', 'priority_rank')) {
                    $table->unsignedInteger('priority_rank')
                        ->default(0)
                        ->after('order');
                }

                // الوزن النسبي للمحفظة
                if (! Schema::hasColumn('portfolios', 'weight')) {
                    $table->decimal('weight', 5, 2)
                        ->default(0.00)
                        ->after('priority_rank');
                }

                // حالة المحفظة الاستراتيجية (منفصلة عن status التشغيلي)
                if (! Schema::hasColumn('portfolios', 'portfolio_status')) {
                    $table->string('portfolio_status', 50)
                        ->default('active')
                        ->after('status');
                }

                // نسبة إنجاز المحفظة (محسوبة من البرامج)
                if (! Schema::hasColumn('portfolios', 'portfolio_progress')) {
                    $table->decimal('portfolio_progress', 5, 2)
                        ->default(0.00)
                        ->after('portfolio_status');
                }
            });

            // إضافة الفهارس (تجاهل الأخطاء إذا موجودة)
            try {
                Schema::table('portfolios', function (Blueprint $table) {
                    $table->index('portfolio_status');
                });
            } catch (Exception $e) {
                // الفهرس موجود مسبقاً
            }

            try {
                Schema::table('portfolios', function (Blueprint $table) {
                    $table->index('priority_rank');
                });
            } catch (Exception $e) {
                // الفهرس موجود مسبقاً
            }
        }

        // 3. تحديث جدول strategic_objectives: إعادة تسمية direction_id إلى portfolio_id
        if (Schema::hasTable('strategic_objectives')) {
            if (Schema::hasColumn('strategic_objectives', 'direction_id')) {
                try {
                    Schema::table('strategic_objectives', function (Blueprint $table) {
                        $table->dropForeign(['direction_id']);
                    });
                } catch (Exception $e) {
                    // FK غير موجود
                }

                Schema::table('strategic_objectives', function (Blueprint $table) {
                    $table->renameColumn('direction_id', 'portfolio_id');
                });
            }

            if (Schema::hasColumn('strategic_objectives', 'portfolio_id')) {
                try {
                    Schema::table('strategic_objectives', function (Blueprint $table) {
                        $table->foreign('portfolio_id')
                            ->references('id')
                            ->on('portfolios')
                            ->nullOnDelete();
                    });
                } catch (Exception $e) {
                    // FK موجود مسبقاً
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // عكس تغييرات strategic_objectives
        Schema::table('strategic_objectives', function (Blueprint $table) {
            if (Schema::hasColumn('strategic_objectives', 'portfolio_id')) {
                $table->dropForeign(['portfolio_id']);
            }
        });

        if (Schema::hasColumn('strategic_objectives', 'portfolio_id')) {
            Schema::table('strategic_objectives', function (Blueprint $table) {
                $table->renameColumn('portfolio_id', 'direction_id');
            });
        }

        Schema::table('strategic_objectives', function (Blueprint $table) {
            if (Schema::hasColumn('strategic_objectives', 'direction_id')) {
                $table->foreign('direction_id')
                    ->references('id')
                    ->on('strategic_directions')
                    ->nullOnDelete();
            }
        });

        // حذف الحقول الجديدة
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropIndex(['portfolio_status']);
            $table->dropIndex(['priority_rank']);
            $table->dropForeign(['portfolio_owner_id']);
            $table->dropColumn([
                'portfolio_owner_id',
                'priority_rank',
                'weight',
                'portfolio_status',
                'portfolio_progress',
            ]);
        });

        // إعادة تسمية الجدول
        Schema::rename('portfolios', 'strategic_directions');
    }
};
