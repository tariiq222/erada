import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Badge, Card, CardContent, CardHeader, CardTitle, StatusBadge } from '@shared/ui';
import type { Recommendation } from '@features/meetings/types';
import { STATUS_COLORS, PRIORITY_COLORS } from '../list/constants';

interface Props {
  recommendation: Recommendation;
}

const RecommendationOverview: React.FC<Props> = ({ recommendation }) => {
  const { t } = useTranslation();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.recommendation.detail.overview')}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge
            type="custom"
            status={recommendation.priority}
            label={
              t(`meetings.recommendation.priorities.${recommendation.priority}`) ??
              recommendation.priority_label ??
              recommendation.priority
            }
            color={PRIORITY_COLORS[recommendation.priority] ?? 'secondary'}
          />
          <StatusBadge
            type="custom"
            status={recommendation.status}
            label={
              t(`meetings.recommendation.statuses.${recommendation.status}`) ||
              recommendation.status_label
            }
            color={STATUS_COLORS[recommendation.status] ?? 'secondary'}
          />
          <span className="font-mono text-xs text-[var(--text-secondary)]">
            {recommendation.reference_number}
          </span>
        </div>

        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">
              {t('meetings.recommendation.fields.decision')}
            </dt>
            <dd className="text-sm">
              {recommendation.decision ? (
                <Link
                  to={`/strategy/decisions/${recommendation.decision_id}`}
                  className="text-[var(--accent-default)] hover:underline"
                >
                  {recommendation.decision.reference_number}: {recommendation.decision.title}
                </Link>
              ) : (
                `#${recommendation.decision_id}`
              )}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">
              {t('meetings.recommendation.fields.assignee')}
            </dt>
            <dd className="text-sm text-[var(--text-secondary)]">
              {recommendation.assignee?.name ?? '—'}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-[var(--text-tertiary)]">
              {t('meetings.recommendation.fields.due_date')}
            </dt>
            <dd className="text-sm tabular-nums text-[var(--text-secondary)]">
              {recommendation.due_date ?? '—'}
              {recommendation.is_overdue && (
                <Badge variant="danger" size="sm" className="ms-2">
                  {t('meetings.recommendation.fields.overdue_badge')}
                </Badge>
              )}
            </dd>
          </div>
          {recommendation.completed_at && (
            <div>
              <dt className="text-xs text-[var(--text-tertiary)]">
                {t('meetings.recommendation.fields.completed_at')}
              </dt>
              <dd className="text-sm tabular-nums text-[var(--text-secondary)]">
                {recommendation.completed_at.slice(0, 16).replace('T', ' ')}
              </dd>
            </div>
          )}
        </dl>

        {recommendation.description && (
          <div>
            <h4 className="mb-1 text-xs font-medium text-[var(--text-tertiary)]">
              {t('meetings.recommendation.fields.description')}
            </h4>
            <p className="whitespace-pre-wrap text-sm text-[var(--text-primary)]">
              {recommendation.description}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default RecommendationOverview;
