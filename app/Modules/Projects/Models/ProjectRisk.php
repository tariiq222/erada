<?php

namespace App\Modules\Projects\Models;

use App\Modules\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectRisk extends Model
{
    /**
     * Project Risk Register — project-scoped lightweight risk list.
     *
     * Distinct from `App\Modules\RiskManagement\Models\Risk`:
     * - ProjectRisk is a per-project register edited inline in the project view; simple open/mitigated/closed lifecycle.
     * - The RiskManagement module is the org-wide risk system with workflows, action plans, evaluations, and transition guards.
     *
     * Both systems intentionally coexist. The project risk register is a convenience surface for project-internal
     * risks; the RiskManagement module is the source of truth for organizational risks with formal review and
     * treatment processes. Do not migrate data between them without a dedicated refactor phase.
     *
     * SoftDeletes added 2026-07-06 (audit P1): prior hard-delete on project cascade
     * bypassed LogsActivity (query-builder delete). Soft-deletes make the
     * cascade path audit-friendly and provide a restore surface.
     */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'risk',
        'probability',
        'impact',
        'response',
        'status',
    ];

    protected $fillable = [
        'project_id',
        'risk',
        'probability',
        'impact',
        'response',
        'status',
        'order',
    ];

    // المشروع
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // حساب مستوى الخطر
    public function getRiskLevelAttribute(): string
    {
        $levels = [
            'low' => 1,
            'medium' => 2,
            'high' => 3,
        ];

        $score = ($levels[$this->probability] ?? 2) * ($levels[$this->impact] ?? 2);

        if ($score >= 6) {
            return 'critical';
        } elseif ($score >= 4) {
            return 'high';
        } elseif ($score >= 2) {
            return 'medium';
        }

        return 'low';
    }
}
