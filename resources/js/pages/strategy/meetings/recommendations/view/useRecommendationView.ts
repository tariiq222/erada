import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useToast } from '@shared/ui/Toast';
import { recommendationsApi } from '@features/meetings/api';
import type { Recommendation } from '@features/meetings/types';

export function useRecommendationView(id: string | undefined) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const [recommendation, setRecommendation] = useState<Recommendation | null>(null);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);

  const fetch = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    try {
      const data = (await recommendationsApi.getOne(Number(id))) as Recommendation;
      setRecommendation(data);
    } catch (err) {
      console.error('Failed to fetch recommendation:', err);
      showToast('error', t('common.error_occurred'));
      navigate('/strategy/meetings/recommendations');
    } finally {
      setLoading(false);
    }
  }, [id, navigate, showToast, t]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  const remove = useCallback(async () => {
    if (!recommendation) return;
    setDeleting(true);
    try {
      await recommendationsApi.delete(recommendation.id);
      showToast('success', t('meetings.recommendation.messages.deleted'));
      navigate('/strategy/meetings/recommendations');
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred');
      showToast('error', msg);
    } finally {
      setDeleting(false);
    }
  }, [recommendation, navigate, showToast, t]);

  const transition = useCallback(
    async (action: 'accept' | 'reject' | 'defer' | 'complete') => {
      if (!recommendation) return;
      await recommendationsApi[action](recommendation.id);
      const msgKey = {
        accept: 'accepted',
        reject: 'rejected',
        defer: 'deferred',
        complete: 'completed_msg',
      }[action];
      showToast('success', t(`meetings.recommendation.messages.${msgKey}`));
      await fetch();
    },
    [recommendation, fetch, showToast, t],
  );

  return {
    recommendation,
    loading,
    deleting,
    remove,
    accept: () => transition('accept'),
    reject: () => transition('reject'),
    defer: () => transition('defer'),
    complete: () => transition('complete'),
    refetch: fetch,
  };
}
