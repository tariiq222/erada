import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardContent, CardHeader, CardTitle, Tooltip } from '@shared/ui';
import { IconCheck, IconX, IconClock, IconCircleCheck } from '@shared/ui/icons';
import type { Recommendation } from '@features/meetings/types';

interface Props {
  recommendation: Recommendation;
  // Per-action capability gates (frontend ↔ backend parity). When `false`
  // the corresponding button is disabled and a localized tooltip explains
  // the missing capability. The parent computes these via `useCan` so the
  // page can additionally hide actions that are entirely irrelevant for
  // the current user/role.
  canAccept: boolean;
  canReject: boolean;
  canDefer: boolean;
  canComplete: boolean;
  onAccept: () => Promise<void>;
  onReject: () => Promise<void>;
  onDefer: () => Promise<void>;
  onComplete: () => Promise<void>;
}

const RecommendationStatusActions: React.FC<Props> = ({
  recommendation,
  canAccept,
  canReject,
  canDefer,
  canComplete,
  onAccept,
  onReject,
  onDefer,
  onComplete,
}) => {
  const { t } = useTranslation();
  // Status-driven transition gates (independent of capabilities).
  const statusCanAccept =
    recommendation.status === 'proposed' || recommendation.status === 'deferred';
  const statusCanReject =
    recommendation.status === 'proposed' || recommendation.status === 'deferred';
  const statusCanDefer =
    recommendation.status === 'proposed' || recommendation.status === 'accepted';
  const statusCanComplete = recommendation.status === 'accepted';
  const invalid = t('meetings.recommendation.messages.invalid_transition');
  const missingCap = t('common.no_permission', { defaultValue: 'لا تملك صلاحية' });

  // Combined: the button is enabled only when BOTH the status permits the
  // transition AND the user has the per-action capability. Tooltip explains
  // which constraint blocks.
  const acceptOk = statusCanAccept && canAccept;
  const rejectOk = statusCanReject && canReject;
  const deferOk = statusCanDefer && canDefer;
  const completeOk = statusCanComplete && canComplete;

  const reasonFor = (statusOk: boolean, capOk: boolean): string => {
    if (!statusOk) return invalid;
    if (!capOk) return missingCap;
    return '';
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.recommendation.fields.status')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Tooltip content={reasonFor(statusCanAccept, canAccept)}>
          <Button
            leftIcon={<IconCheck className="h-4 w-4" />}
            disabled={!acceptOk}
            aria-label={t('meetings.recommendation.actions.accept')}
            onClick={onAccept}
          >
            {t('meetings.recommendation.actions.accept')}
          </Button>
        </Tooltip>
        <Tooltip content={reasonFor(statusCanReject, canReject)}>
          <Button
            variant="danger"
            leftIcon={<IconX className="h-4 w-4" />}
            disabled={!rejectOk}
            aria-label={t('meetings.recommendation.actions.reject')}
            onClick={onReject}
          >
            {t('meetings.recommendation.actions.reject')}
          </Button>
        </Tooltip>
        <Tooltip content={reasonFor(statusCanDefer, canDefer)}>
          <Button
            variant="outline"
            leftIcon={<IconClock className="h-4 w-4" />}
            disabled={!deferOk}
            aria-label={t('meetings.recommendation.actions.defer')}
            onClick={onDefer}
          >
            {t('meetings.recommendation.actions.defer')}
          </Button>
        </Tooltip>
        <Tooltip content={reasonFor(statusCanComplete, canComplete)}>
          <Button
            leftIcon={<IconCircleCheck className="h-4 w-4" />}
            disabled={!completeOk}
            aria-label={t('meetings.recommendation.actions.complete')}
            onClick={onComplete}
          >
            {t('meetings.recommendation.actions.complete')}
          </Button>
        </Tooltip>
      </CardContent>
    </Card>
  );
};

export default RecommendationStatusActions;