import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Badge, StatusBadge } from '@shared/ui';
import type { Recommendation } from '@features/meetings/types';
import type { CustomBadgeColor } from '@shared/ui';

const STATUS_COLOR: Record<string, CustomBadgeColor> = {
  proposed: 'warning',
  accepted: 'info',
  rejected: 'danger',
  deferred: 'secondary',
  completed: 'success',
};

interface Props {
  recommendation: Recommendation;
}

const RecommendationsSectionCard: React.FC<Props> = ({ recommendation }) => {
  const { t } = useTranslation();
  return (
    <div className="rounded-md border border-[var(--border-default)] bg-[var(--surface-default)] p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="font-mono text-xs text-[var(--text-tertiary)]">{recommendation.reference_number}</p>
          <Link
            to={`/strategy/meetings/recommendations/${recommendation.id}`}
            className="text-sm font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
          >
            {recommendation.title}
          </Link>
          <p className="mt-1 text-xs text-[var(--text-secondary)]">
            {recommendation.assignee?.name ?? '—'} · {recommendation.due_date ?? '—'}
            {recommendation.is_overdue && (
              <Badge variant="danger" size="sm" className="ms-2">
                {t('meetings.recommendation.fields.overdue_badge')}
              </Badge>
            )}
          </p>
        </div>
        <StatusBadge
          type="custom"
          status={recommendation.status}
          label={t(`meetings.recommendation.statuses.${recommendation.status}`) || recommendation.status_label}
          color={STATUS_COLOR[recommendation.status] ?? 'secondary'}
          size="sm"
        />
      </div>
    </div>
  );
};

export default RecommendationsSectionCard;
