import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { decisionsApi } from '@entities/strategy';
import type { Recommendation } from '@features/meetings/types';
import { useRecommendationForm, RecommendationDetailsSection } from './form';

export interface RecommendationFormProps {
  mode?: 'page' | 'modal';
  initial?: Partial<Recommendation>;
  prefill?: { decision_id: number };
  onSuccess?: (recommendation: Recommendation) => void;
  onCancel?: () => void;
}

const RecommendationForm: React.FC<RecommendationFormProps> = ({
  // mode is used by parent to distinguish page vs modal usage; form behaviour is the same
  mode: _mode = 'page',
  initial,
  prefill,
  onSuccess,
  onCancel,
}) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const { formData, setField, save, isLoading } = useRecommendationForm({
    ...(initial ?? {}),
    decision_id: prefill?.decision_id ?? initial?.decision_id,
  });

  const [decisionOptions, setDecisionOptions] = useState<{ value: number; label: string }[]>([]);
  const [userOptions] = useState<{ value: number; label: string }[]>([]);

  useEffect(() => {
    (
      decisionsApi.getAll as (
        p: Record<string, string>,
      ) => Promise<{ data: { id: number; title: string }[] }>
    )({ per_page: '100' })
      .then((res) => setDecisionOptions(res.data.map((d) => ({ value: d.id, label: d.title }))))
      .catch(() => {});
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const rec = await save();
      showToast(
        'success',
        t(
          initial?.id
            ? 'meetings.recommendation.messages.updated'
            : 'meetings.recommendation.messages.created',
        ),
      );
      if (onSuccess) {
        onSuccess(rec);
      } else {
        navigate(`/strategy/meetings/recommendations/${rec.id}`);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred');
      showToast('error', msg);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <RecommendationDetailsSection
        data={formData}
        onChange={setField}
        decisionOptions={decisionOptions}
        userOptions={userOptions}
        decisionDisabled={Boolean(prefill)}
      />

      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="ghost"
          onClick={() => (onCancel ? onCancel() : navigate(-1))}
        >
          {t('meetings.recommendation.form.cancel')}
        </Button>
        <Button type="submit" disabled={isLoading}>
          {t(
            initial?.id
              ? 'meetings.recommendation.form.submit_update'
              : 'meetings.recommendation.form.submit_create',
          )}
        </Button>
      </div>
    </form>
  );
};

export default RecommendationForm;
