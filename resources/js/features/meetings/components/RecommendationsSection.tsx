import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button, EmptyState, Skeleton } from '@shared/ui';
import { IconClipboardCheck, IconPlus } from '@shared/ui/icons';
import RecommendationsSectionCard from './RecommendationsSectionCard';
import CreateRecommendationModal from './CreateRecommendationModal';
import { useRecommendationsSection } from './useRecommendationsSection';

export interface RecommendationsSectionProps {
  decision_id: number;
  decision_title: string;
  permissions: {
    canView: boolean;
    canCreate: boolean;
    canEdit: boolean;
  };
}

const RecommendationsSection: React.FC<RecommendationsSectionProps> = ({
  decision_id,
  decision_title,
  permissions,
}) => {
  const { t } = useTranslation();
  const { recommendations, loading, refetch } = useRecommendationsSection({ decision_id });
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-base font-semibold text-[var(--text-primary)]">
          {t('meetings.recommendation.section.header', { name: decision_title })}
          {recommendations.length > 0 && (
            <span className="ms-2 inline-flex items-center rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs tabular-nums text-[var(--text-secondary)]">
              {recommendations.length}
            </span>
          )}
        </h3>
        <div className="flex items-center gap-2">
          {permissions.canCreate && (
            <Button
              size="sm"
              leftIcon={<IconPlus className="h-4 w-4" />}
              onClick={() => setShowCreate(true)}
            >
              {t('meetings.recommendation.list.new_button')}
            </Button>
          )}
          {permissions.canView && recommendations.length > 0 && (
            <Link
              to={`/strategy/meetings/recommendations?decision_id=${decision_id}`}
              className="text-sm text-[var(--accent-default)] hover:underline"
            >
              {t('meetings.recommendation.section.view_all')}
            </Link>
          )}
        </div>
      </div>

      {loading ? (
        <Skeleton className="h-24 w-full" />
      ) : recommendations.length === 0 ? (
        <EmptyState
          icon={IconClipboardCheck}
          title={t('meetings.recommendation.section.empty', { entity: 'القرار' })}
          action={
            permissions.canCreate ? (
              <Button
                leftIcon={<IconPlus className="h-4 w-4" />}
                onClick={() => setShowCreate(true)}
              >
                {t('meetings.recommendation.section.create_cta')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
          {recommendations.map((r) => (
            <RecommendationsSectionCard key={r.id} recommendation={r} />
          ))}
        </div>
      )}

      <CreateRecommendationModal
        open={showCreate}
        decision_id={decision_id}
        onClose={() => setShowCreate(false)}
        onCreated={() => refetch()}
      />
    </div>
  );
};

export default RecommendationsSection;
