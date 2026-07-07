<?php

namespace App\Modules\Strategy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Portfolio Resource
 *
 * يُستخدم لتنسيق بيانات المحفظة في الـ API response
 * ملاحظة: الـ Frontend يعرض هذه البيانات باسم "الالتزام التنفيذي"
 */
class PortfolioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'rationale' => $this->rationale,

            // ارتباط بالخطة الأعلى
            'strategic_plan_link' => $this->strategic_plan_link,

            // جهة التوجيه
            'directive_source' => $this->directive_source,
            'directive_source_other' => $this->directive_source_other,
            'directive_source_label' => $this->directive_source_label,

            // التواريخ
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),

            // الحالة التشغيلية
            'status' => $this->status,
            'status_label' => $this->status_label,

            // الحالة الاستراتيجية للمحفظة
            'portfolio_status' => $this->portfolio_status,
            'portfolio_status_label' => $this->portfolio_status_label,

            // الإنجاز والوزن
            'portfolio_progress' => (float) $this->portfolio_progress,
            'progress' => $this->when(
                isset($this->progress),
                fn () => (float) $this->progress
            ),
            'weight' => (float) $this->weight,
            'priority_rank' => (int) $this->priority_rank,

            // الترتيب
            'order' => (int) $this->order,

            // العلاقات
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            // الإحصائيات (PMI Standard: Programs)
            'programs_count' => $this->when(
                isset($this->programs_count),
                fn () => (int) $this->programs_count
            ),

            // البرامج (المبادرات) - العلاقة المباشرة
            'programs' => $this->whenLoaded('programs'),

            // التواريخ
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // بيانات إضافية للتوافق مع الـ Frontend الحالي
            // هذه الحقول تضمن التوافق الخلفي
            '_meta' => [
                'display_name' => 'الالتزام التنفيذي', // للـ Frontend
                'can_be_closed' => $this->when(
                    method_exists($this->resource, 'canBeClosedStrategically'),
                    fn () => $this->canBeClosedStrategically()
                ),
            ],
        ];
    }
}
