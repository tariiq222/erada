<?php

namespace App\Modules\RiskManagement\Services;

use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskAlertType;
use App\Modules\RiskManagement\Enums\RiskLevel;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Models\RiskAlert;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\RiskManagement\Models\RiskStatusChange;
use App\Modules\RiskManagement\Notifications\RiskLevelEscalatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskLifecycleService
{
    public function __construct(
        protected RiskScoreCalculator $calculator
    ) {}

    /**
     * Create an assessment and snapshot the latest score onto the parent risk.
     */
    public function recordAssessment(
        Risk $risk,
        User $assessor,
        int $likelihood,
        int $impact,
        ?int $residualLikelihood,
        ?int $residualImpact,
        ?string $notes,
        ?string $nextReviewAt
    ): RiskAssessment {
        return DB::transaction(function () use (
            $risk, $assessor, $likelihood, $impact,
            $residualLikelihood, $residualImpact, $notes, $nextReviewAt
        ) {
            $inherent = $this->calculator->calculate($likelihood, $impact);
            $residualScore = null;
            $residualLevel = null;

            if ($residualLikelihood !== null && $residualImpact !== null) {
                $residual = $this->calculator->calculate($residualLikelihood, $residualImpact);
                $residualScore = $residual['score'];
                $residualLevel = $residual['level']->value;
            }

            $previousLevel = $risk->current_level;

            $assessment = RiskAssessment::create([
                'risk_id' => $risk->id,
                'organization_id' => $risk->organization_id,
                'likelihood' => $likelihood,
                'impact' => $impact,
                'score' => $inherent['score'],
                'level' => $inherent['level']->value,
                'residual_likelihood' => $residualLikelihood,
                'residual_impact' => $residualImpact,
                'residual_score' => $residualScore,
                'residual_level' => $residualLevel,
                'assessor_id' => $assessor->id,
                'notes' => $notes,
                'next_review_at' => $nextReviewAt,
            ]);

            $risk->forceFill([
                'current_likelihood' => $likelihood,
                'current_impact' => $impact,
                'current_score' => $inherent['score'],
                'current_level' => $inherent['level']->value,
            ])->save();

            // Notify if the level escalated from a previously lower level.
            if ($previousLevel !== null && $this->isEscalation($previousLevel, $inherent['level'])) {
                $alert = $this->logAlert($risk, null, $assessment, RiskAlertType::LevelEscalated, [
                    'previous_level' => $previousLevel,
                    'new_level' => $inherent['level']->value,
                ]);

                if ($risk->owner) {
                    DB::afterCommit(fn () => $risk->owner->notify(new RiskLevelEscalatedNotification($risk, $alert)));
                }
            }

            return $assessment;
        });
    }

    /**
     * Apply a status change with audit trail; rejects illegal transitions.
     */
    public function changeStatus(Risk $risk, RiskStatus $target, User $changer, ?string $reason): RiskStatusChange
    {
        if (! $risk->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => 'الانتقال المطلوب للحالة غير مسموح به',
            ]);
        }

        return DB::transaction(function () use ($risk, $target, $changer, $reason) {
            $from = $risk->status;

            $change = RiskStatusChange::create([
                'risk_id' => $risk->id,
                'organization_id' => $risk->organization_id,
                'from_status' => $from?->value,
                'to_status' => $target->value,
                'changed_by' => $changer->id,
                'reason' => $reason,
            ]);

            $risk->forceFill(['status' => $target->value])->save();

            return $change;
        });
    }

    /**
     * Persist a RiskAlert row for an arbitrary alert type. The actual user
     * notification (queued mail+database) is dispatched by the calling
     * command/job, not here.
     */
    public function logAlert(
        Risk $risk,
        ?RiskAction $action,
        ?RiskAssessment $assessment,
        RiskAlertType $type,
        array $payload
    ): RiskAlert {
        return RiskAlert::create([
            'risk_id' => $risk->id,
            'risk_action_id' => $action?->id,
            'risk_assessment_id' => $assessment?->id,
            'organization_id' => $risk->organization_id,
            'type' => $type->value,
            'payload' => $payload,
            'sent_to' => $risk->owner_id,
            'sent_at' => now(),
        ]);
    }

    private function isEscalation(string $previous, RiskLevel $current): bool
    {
        $order = [
            RiskLevel::Low->value => 1,
            RiskLevel::Medium->value => 2,
            RiskLevel::High->value => 3,
            RiskLevel::Critical->value => 4,
        ];

        return ($order[$current->value] ?? 0) > ($order[$previous] ?? 0);
    }
}
