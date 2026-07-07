<?php

namespace App\Modules\Projects\Models;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Tasks\Models\Task;
use Database\Factories\ProjectExpenseFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ProjectExpense extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): Factory
    {
        return ProjectExpenseFactory::new();
    }

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'title',
        'description',
        'amount',
        'original_amount',
        'category',
        'expense_date',
        'reference_number',
    ];

    protected $fillable = [
        'project_id',
        'task_id',
        'created_by',
        'title',
        'description',
        'amount',
        'original_amount',
        'category',
        'expense_date',
        'reference_number',
        'attachment_path',
        'is_finalized',
        'finalized_at',
        'finalized_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'expense_date' => 'date',
        'is_finalized' => 'boolean',
        'finalized_at' => 'datetime',
    ];

    /**
     * تصنيفات المصروفات
     */
    public const CATEGORIES = [
        'human_resources' => 'موارد بشرية',
        'materials' => 'مواد ومعدات',
        'services' => 'خدمات خارجية',
        'operational' => 'تشغيلية',
        'travel' => 'سفر وتنقل',
        'training' => 'تدريب',
        'other' => 'أخرى',
    ];

    /**
     * المشروع
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * المهمة (اختياري)
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * منشئ المصروف
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * الحصول على اسم التصنيف بالعربية
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * تحديث إجمالي المصروفات في المشروع
     */
    protected static function booted(): void
    {
        static::creating(function (ProjectExpense $expense) {
            $expense->original_amount = $expense->amount;
        });

        static::saved(function (ProjectExpense $expense) {
            // Audit 2026-07-06 (P1-5): without this guard, late expense saves
            // after a project has been cancelled or soft-deleted still update
            // `projects.spent_amount`, which then drifts from the visible
            // financials. Skip the recompute when the parent project is gone
            // (cancelled projects keep `spent_amount` as the snapshot at
            // cancellation time; soft-deleted projects are invisible anyway).
            if ($expense->project()->onlyTrashed()->exists()
                || ! $expense->project()->exists()) {
                return;
            }
            $expense->updateProjectSpentAmount();
        });

        static::deleted(function (ProjectExpense $expense) {
            $expense->updateProjectSpentAmount();
        });
    }

    /**
     * تحديث مجموع المصروفات في المشروع
     *
     * يُحاط بعملية ذرّية مع قفل الصفّ على مستوى المشروع (lockForUpdate) لمنع
     * سباق read-then-write عند إنشاء/تعديل عدة مصروفات في وقت متقارب، كي لا
     * تُفقد قيمة spent_amount بين قراءة المجموع وكتابته.
     */
    public function updateProjectSpentAmount(): void
    {
        DB::transaction(function () {
            // Defensive: if the parent project is missing or trashed, the
            // recompute target is undefined. The static::saved guard above
            // already covers the common case; this is the inner belt-and-braces.
            $project = $this->project()->lockForUpdate()->first();
            if (! $project || $project->trashed()) {
                return;
            }
            $total = self::where('project_id', $this->project_id)->sum('amount');
            $project->update(['spent_amount' => $total]);
        });
    }
}
