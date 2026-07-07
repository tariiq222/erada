import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState, Skeleton } from '@shared/ui';
import { IconClipboardCheck } from '@shared/ui/icons';
import { recommendationsApi } from '@features/meetings/api';
import type { DecidableAlias, Recommendation } from '@features/meetings/types';
import RecommendationCard from '@features/meetings/RecommendationCard';

const ALIAS_TO_FQCN: Record<DecidableAlias, string> = {
  project: 'App\\Modules\\Projects\\Models\\Project',
  portfolio: 'App\\Modules\\Strategy\\Models\\Portfolio',
  program: 'App\\Modules\\Strategy\\Models\\Program',
  risk: 'App\\Modules\\RiskManagement\\Models\\Risk',
};

const ALIAS_AR: Record<DecidableAlias, string> = {
  project: 'مشروع',
  portfolio: 'هدف تنفيذي',
  program: 'مبادرة',
  risk: 'مخاطرة',
};

export interface DecisionsSectionProps {
  decidable_type: DecidableAlias;
  decidable_id: number;
  decidable_name: string;
  permissions: {
    canView: boolean;
    canCreate: boolean;
    canEdit: boolean;
  };
}

interface Paginated<T> {
  data: T[];
}

const normalize = (res: unknown): Recommendation[] => {
  if (Array.isArray(res)) return res as Recommendation[];
  const wrapped = res as Paginated<Recommendation> | undefined;
  return wrapped?.data ?? [];
};

// Direction B: DecisionsSection is now a thin read-only view over rulings
// (kind='ruling') whose decidable matches the host entity. The create flow
// moved to the meeting page (ResolutionsSection). canCreate is preserved
// for caller compatibility but is no longer wired.
const DecisionsSection: React.FC<DecisionsSectionProps> = ({
  decidable_type,
  decidable_id,
  decidable_name,
  permissions: _permissions,
}) => {
  const { t } = useTranslation();
  const [recommendations, setRecommendations] = useState<Recommendation[]>([]);
  const [loading, setLoading] = useState(true);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await recommendationsApi.getAll({
        decidable_type: ALIAS_TO_FQCN[decidable_type],
        decidable_id: String(decidable_id),
        kind: 'ruling',
        per_page: '5',
      });
      setRecommendations(normalize(res));
    } catch (err) {
      console.error('Failed to fetch rulings for section:', err);
      setRecommendations([]);
    } finally {
      setLoading(false);
    }
  }, [decidable_type, decidable_id]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  const renderBody = (): React.ReactNode => {
    if (loading) return <Skeleton className="h-24 w-full" />;
    if (recommendations.length === 0) {
      return (
        <EmptyState
          icon={IconClipboardCheck}
          title={t('meetings.decision.section.empty', {
            entity: ALIAS_AR[decidable_type],
          })}
        />
      );
    }
    return (
      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
        {recommendations.map((r) => (
          <RecommendationCard key={r.id} recommendation={r} onChanged={fetch} />
        ))}
      </div>
    );
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-base font-semibold text-[var(--text-primary)]">
          {t('meetings.decision.section.header', { name: decidable_name })}
          {recommendations.length > 0 && (
            <span className="ms-2 inline-flex items-center rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs tabular-nums text-[var(--text-secondary)]">
              {recommendations.length}
            </span>
          )}
        </h3>
      </div>
      {renderBody()}
    </div>
  );
};

export default DecisionsSection;