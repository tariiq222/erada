import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  Modal,
  ModalBody,
  Skeleton,
} from '@shared/ui';
import { IconClipboardCheck, IconPlus } from '@shared/ui/icons';
import { recommendationsApi } from '@features/meetings/api';
import type { Recommendation } from '@features/meetings/types';
import RecommendationCard from '@features/meetings/RecommendationCard';
import RecommendationForm from '@features/meetings/RecommendationForm';

export interface ResolutionsSectionProps {
  meetingId: number;
  permissions: {
    canView: boolean;
    canCreate: boolean;
  };
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const ResolutionsSection: React.FC<ResolutionsSectionProps> = ({
  meetingId,
  permissions,
}) => {
  const { t } = useTranslation();
  const [recommendations, setRecommendations] = useState<Recommendation[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = (await recommendationsApi.getAll({
        meeting_id: String(meetingId),
        per_page: '50',
      })) as Paginated<Recommendation> | Recommendation[];
      setRecommendations(Array.isArray(res) ? res : res.data ?? []);
    } catch (err) {
      console.error('Failed to fetch recommendations:', err);
      setRecommendations([]);
    } finally {
      setLoading(false);
    }
  }, [meetingId]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  const counts = {
    rulings: recommendations.filter((r) => r.kind === 'ruling').length,
    actions: recommendations.filter((r) => r.kind === 'action_item').length,
  };

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>
            {t('meetings.recommendation.resolutions.header', {
              defaultValue: 'قرارات وإجراءات الاجتماع',
            })}
            {recommendations.length > 0 && (
              <span className="ms-2 inline-flex items-center rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs tabular-nums text-[var(--text-secondary)]">
                {recommendations.length}
              </span>
            )}
          </CardTitle>
          {permissions.canCreate && (
            <Button
              size="sm"
              leftIcon={<IconPlus className="h-4 w-4" />}
              onClick={() => setShowCreate(true)}
            >
              {t('meetings.recommendation.resolutions.new_button', {
                defaultValue: 'إضافة قرار/إجراء',
              })}
            </Button>
          )}
        </div>
        {recommendations.length > 0 && (
          <p className="mt-1 text-xs text-[var(--text-tertiary)]">
            {t('meetings.recommendation.resolutions.summary', {
              defaultValue: '{{rulings}} قرار · {{actions}} إجراء',
              rulings: counts.rulings,
              actions: counts.actions,
            })}
          </p>
        )}
      </CardHeader>
      <CardContent>
        {loading ? (
          <Skeleton className="h-24 w-full" />
        ) : recommendations.length === 0 ? (
          <EmptyState
            icon={IconClipboardCheck}
            title={t('meetings.recommendation.resolutions.empty', {
              defaultValue: 'لا توجد قرارات أو إجراءات بعد',
            })}
            action={
              permissions.canCreate ? (
                <Button
                  leftIcon={<IconPlus className="h-4 w-4" />}
                  onClick={() => setShowCreate(true)}
                >
                  {t('meetings.recommendation.resolutions.create_cta', {
                    defaultValue: 'أنشئ أول قرار أو إجراء',
                  })}
                </Button>
              ) : undefined
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-3">
            {recommendations.map((r) => (
              <RecommendationCard
                key={r.id}
                recommendation={r}
                onChanged={fetch}
              />
            ))}
          </div>
        )}
      </CardContent>

      <Modal
        open={showCreate}
        onClose={() => setShowCreate(false)}
        size="lg"
        title={t('meetings.recommendation.form.create_title')}
      >
        <ModalBody>
          <RecommendationForm
            mode="modal"
            prefill={{ meeting_id: meetingId }}
            onSuccess={() => {
              setShowCreate(false);
              fetch();
            }}
            onCancel={() => setShowCreate(false)}
          />
        </ModalBody>
      </Modal>
    </Card>
  );
};

export default ResolutionsSection;