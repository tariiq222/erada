import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Skeleton } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { recommendationsApi } from '@features/meetings/api';
import MeetingRecommendationForm from '@features/meetings/RecommendationForm';
import type { Recommendation } from '@features/meetings/types';

export interface RecommendationFormProps {
  mode?: 'page' | 'modal';
  initial?: Partial<Recommendation>;
  prefill?: { meeting_id?: number; decision_id?: number };
  onSuccess?: (recommendation: Recommendation) => void;
  onCancel?: () => void;
}

const RecommendationForm: React.FC<RecommendationFormProps> = ({
  mode = 'page',
  initial,
  prefill,
  onSuccess,
  onCancel,
}) => {
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const { t } = useTranslation();
  const { showToast } = useToast();
  const navigate = useNavigate();
  const [recommendation, setRecommendation] = useState<Partial<Recommendation> | undefined>(initial);
  const [loading, setLoading] = useState(Boolean(id && !initial));
  const [denied, setDenied] = useState(false);

  // Forward ?meeting_id=... from the route into the inner form's prefill
  // so deep links into /recommendations/new can preselect the meeting.
  const queryPrefill = useMemo(() => {
    const raw = searchParams.get('meeting_id');
    const parsed = raw ? Number(raw) : NaN;
    return Number.isFinite(parsed) && parsed > 0 ? { meeting_id: parsed } : undefined;
  }, [searchParams]);
  const mergedPrefill = prefill?.meeting_id
    ? prefill
    : queryPrefill;

  useEffect(() => {
    if (!id || initial) return;
    setLoading(true);
    recommendationsApi.getOne(Number(id))
      .then((record) => {
        if (record.allowed_actions?.update !== true) {
          setDenied(true);
          showToast('error', t('common.forbidden'));
          navigate('/strategy/meetings/recommendations');
          return;
        }
        setRecommendation(record);
      })
      .catch((err: unknown) => {
        const message = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
          ? err.message
          : t('common.error_occurred');
        showToast('error', message);
        navigate('/strategy/meetings/recommendations');
      })
      .finally(() => setLoading(false));
  }, [id, initial, navigate, showToast, t]);

  if (loading) return <Skeleton className="h-64 w-full" />;
  if (denied) return null;

  return (
    <MeetingRecommendationForm
      mode={mode}
      initial={recommendation}
      prefill={mergedPrefill?.meeting_id ? { meeting_id: mergedPrefill.meeting_id } : undefined}
      onCancel={onCancel ?? (() => navigate(-1))}
      onSuccess={(saved) => {
        if (onSuccess) {
          onSuccess(saved);
          return;
        }
        navigate(`/strategy/meetings/recommendations/${saved.id}`);
      }}
    />
  );
};

export default RecommendationForm;
