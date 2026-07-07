<?php

namespace Database\Seeders\Mock;

use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use Illuminate\Database\Seeder;

class AdditionalDataSeeder extends Seeder
{
    public function run(array $users, array $projects, array $programs): void
    {
        $this->createProjectComments($users, $projects);
        $this->createProgramReviews($users, $programs);
        $this->createProgramRulings($users, $programs);
    }

    private function createProjectComments(array $users, array $projects): void
    {
        foreach (array_slice($projects, 0, 5) as $project) {
            $commentCount = rand(2, 5);
            for ($i = 0; $i < $commentCount; $i++) {
                $project->comments()->create([
                    'user_id' => $users[rand(0, count($users) - 1)]->id,
                    'content' => 'تعليق على المشروع: '.$project->name.' - رقم '.($i + 1),
                ]);
            }
        }
    }

    private function createProgramReviews(array $users, array $programs): void
    {
        foreach (array_slice($programs, 0, 4) as $program) {
            $reviewDate = now()->subDays(rand(1, 30));
            Review::create([
                'reviewable_type' => Program::class,
                'reviewable_id' => $program->id,
                'title' => 'مراجعة دورية - '.$reviewDate->format('Y-m'),
                'type' => ['monthly', 'quarterly'][rand(0, 1)],
                'pdca_phase' => ['plan', 'do', 'check', 'act'][rand(0, 3)],
                'review_date' => $reviewDate,
                'period_start' => $reviewDate->copy()->subMonth(),
                'period_end' => $reviewDate,
                'progress_snapshot' => rand(40, 95),
                'overall_status' => ['on_track', 'at_risk', 'off_track'][rand(0, 2)],
                'achievements' => 'الإنجازات الرئيسية خلال الفترة: تم إنجاز المهام المحددة',
                'challenges' => 'التحديات والمشكلات المواجهة: بعض العوائق التقنية',
                'lessons_learned' => 'الدروس المستفادة: أهمية التخطيط المسبق',
                'next_steps' => 'الخطوات القادمة المخطط لها',
                'recommendations' => 'التوصيات: زيادة الموارد المخصصة',
                'conducted_by' => $users[rand(0, 2)]->id,
                'attendees' => [$users[rand(3, 5)]->id, $users[rand(6, 8)]->id],
            ]);
        }
    }

    /**
     * Direction B (Phase R2): the program-level rulings live on the unified
     * `recommendations` table with `kind=ruling`. The legacy `decisions`
     * model is gone, so we seed rulings here instead.
     */
    private function createProgramRulings(array $users, array $programs): void
    {
        $rulingTypes = ['approval', 'change_request', 'resource_allocation', 'scope_change', 'budget_change'];
        $statuses = [
            Recommendation::STATUS_PENDING,
            Recommendation::STATUS_APPROVED,
            Recommendation::STATUS_REJECTED,
            Recommendation::STATUS_DEFERRED,
        ];

        foreach (array_slice($programs, 0, 3) as $program) {
            $status = $statuses[array_rand($statuses)];
            $requestedBy = $users[rand(2, 5)]->id;
            $madeBy = $status !== Recommendation::STATUS_PENDING ? $users[rand(0, 1)]->id : null;

            Recommendation::create([
                'kind' => Recommendation::KIND_RULING,
                'type' => $rulingTypes[array_rand($rulingTypes)],
                'title' => 'قرار بشأن '.$program->name,
                'description' => 'وصف القرار المتخذ والأسباب',
                'rationale' => 'المبررات والأسباب المنطقية للقرار',
                'decidable_type' => Program::class,
                'decidable_id' => $program->id,
                'status' => $status,
                'decision_date' => $status !== Recommendation::STATUS_PENDING ? now()->subDays(rand(5, 30)) : null,
                'effective_date' => $status === Recommendation::STATUS_APPROVED ? now()->addDays(rand(1, 14)) : null,
                'priority' => Recommendation::PRIORITY_MEDIUM,
                'requested_by' => $requestedBy,
                'made_by' => $madeBy,
                'organization_id' => $program->organization_id,
            ]);
        }
    }
}
