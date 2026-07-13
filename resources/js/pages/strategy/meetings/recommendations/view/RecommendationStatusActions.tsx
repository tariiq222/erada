import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardContent, CardHeader, CardTitle, Tooltip } from '@shared/ui';
import { IconCheck, IconCircleCheck, IconClock, IconX } from '@shared/ui/icons';
import type { Recommendation } from '@features/meetings/types';

interface Props {
  recommendation: Recommendation;
  canApprove: boolean;
  canAccept: boolean;
  canReject: boolean;
  canDefer: boolean;
  canComplete: boolean;
  onApprove: () => Promise<void>;
  onAccept: () => Promise<void>;
  onReject: () => Promise<void>;
  onDefer: () => Promise<void>;
  onComplete: () => Promise<void>;
}

const RecommendationStatusActions: React.FC<Props> = (props) => {
  const { t } = useTranslation();
  const isRuling = props.recommendation.kind === 'ruling';
  const status = props.recommendation.status;
  const invalid = t('meetings.recommendation.messages.invalid_transition');
  const reasonFor = (statusOk: boolean) => statusOk ? '' : invalid;
  const ruling = {
    approve: status === 'pending' || status === 'deferred',
    reject: status === 'pending' || status === 'deferred',
    defer: status === 'pending' || status === 'approved',
  };
  const actionItem = {
    accept: status === 'proposed' || status === 'deferred',
    reject: status === 'proposed' || status === 'deferred',
    defer: status === 'proposed' || status === 'accepted',
    complete: status === 'accepted',
  };

  const hasVisibleAction = isRuling
    ? props.canApprove || props.canReject || props.canDefer
    : props.canAccept || props.canReject || props.canDefer || props.canComplete;

  if (!hasVisibleAction) return null;

  return (
    <Card>
      <CardHeader><CardTitle>{t('meetings.recommendation.fields.status')}</CardTitle></CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        {isRuling ? (
          <>
            {props.canApprove && <Tooltip content={reasonFor(ruling.approve)}>
              <Button leftIcon={<IconCheck className="h-4 w-4" />} disabled={!ruling.approve || !props.canApprove} aria-label={t('meetings.recommendation.actions.approve')} onClick={props.onApprove}>
                {t('meetings.recommendation.actions.approve')}
              </Button>
            </Tooltip>}
            {props.canReject && <Tooltip content={reasonFor(ruling.reject)}>
              <Button variant="danger" leftIcon={<IconX className="h-4 w-4" />} disabled={!ruling.reject || !props.canReject} aria-label={t('meetings.recommendation.actions.reject')} onClick={props.onReject}>
                {t('meetings.recommendation.actions.reject')}
              </Button>
            </Tooltip>}
            {props.canDefer && <Tooltip content={reasonFor(ruling.defer)}>
              <Button variant="outline" leftIcon={<IconClock className="h-4 w-4" />} disabled={!ruling.defer || !props.canDefer} aria-label={t('meetings.recommendation.actions.defer')} onClick={props.onDefer}>
                {t('meetings.recommendation.actions.defer')}
              </Button>
            </Tooltip>}
          </>
        ) : (
          <>
            {props.canAccept && <Tooltip content={reasonFor(actionItem.accept)}>
              <Button leftIcon={<IconCheck className="h-4 w-4" />} disabled={!actionItem.accept || !props.canAccept} aria-label={t('meetings.recommendation.actions.accept')} onClick={props.onAccept}>
                {t('meetings.recommendation.actions.accept')}
              </Button>
            </Tooltip>}
            {props.canReject && <Tooltip content={reasonFor(actionItem.reject)}>
              <Button variant="danger" leftIcon={<IconX className="h-4 w-4" />} disabled={!actionItem.reject || !props.canReject} aria-label={t('meetings.recommendation.actions.reject')} onClick={props.onReject}>
                {t('meetings.recommendation.actions.reject')}
              </Button>
            </Tooltip>}
            {props.canDefer && <Tooltip content={reasonFor(actionItem.defer)}>
              <Button variant="outline" leftIcon={<IconClock className="h-4 w-4" />} disabled={!actionItem.defer || !props.canDefer} aria-label={t('meetings.recommendation.actions.defer')} onClick={props.onDefer}>
                {t('meetings.recommendation.actions.defer')}
              </Button>
            </Tooltip>}
            {props.canComplete && <Tooltip content={reasonFor(actionItem.complete)}>
              <Button leftIcon={<IconCircleCheck className="h-4 w-4" />} disabled={!actionItem.complete || !props.canComplete} aria-label={t('meetings.recommendation.actions.complete')} onClick={props.onComplete}>
                {t('meetings.recommendation.actions.complete')}
              </Button>
            </Tooltip>}
          </>
        )}
      </CardContent>
    </Card>
  );
};

export default RecommendationStatusActions;
