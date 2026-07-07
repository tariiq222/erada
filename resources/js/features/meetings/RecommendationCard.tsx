import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  StatusBadge,
  Tooltip,
  type CustomBadgeColor,
} from '@shared/ui';
import {
  IconAlertTriangle,
  IconCheck,
  IconCircleCheck,
  IconClock,
  IconClipboardCheck,
  IconClipboardList,
  IconX,
} from '@shared/ui/icons';
import { useToast } from '@shared/ui/Toast';
import { recommendationsApi } from './api';
import { useCan } from '@shared/api/access';
import type { Recommendation } from './types';
import DeferModal from './DeferModal';

export interface RecommendationCardProps {
  recommendation: Recommendation;
  onChanged?: () => void;
}

const STATUS_COLOR: Record<string, CustomBadgeColor> = {
  proposed: 'warning',
  pending: 'warning',
  accepted: 'info',
  approved: 'success',
  rejected: 'danger',
  deferred: 'secondary',
  completed: 'success',
};

const PRIORITY_COLOR: Record<string, CustomBadgeColor> = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

type TransitionAction = 'approve' | 'accept' | 'reject' | 'defer' | 'complete';

const tooltipFor = (allowed: boolean, blockedReason: string): string =>
  allowed ? '' : blockedReason;

const RecommendationCard: React.FC<RecommendationCardProps> = ({
  recommendation,
  onChanged,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [busy, setBusy] = useState<TransitionAction | null>(null);
  const [showDefer, setShowDefer] = useState(false);

  // Per-action capability gates (frontend ↔ backend parity).
  // Backend enforces `recommendations.{approve,reject,defer,accept,complete}`
  // — the legacy `meetings.record_decisions` capability is intentionally NOT
  // consulted here so the UI matches what the engine will accept.
  const canApprove = useCan('recommendations.approve');
  const canReject = useCan('recommendations.reject');
  const canDefer = useCan('recommendations.defer');
  const canAccept = useCan('recommendations.accept');
  const canComplete = useCan('recommendations.complete');
  const isRuling = recommendation.kind === 'ruling';

  // Transition gates per spec
  const rulingActionable =
    recommendation.status === 'pending' || recommendation.status === 'deferred';
  const actionProposeable =
    recommendation.status === 'proposed' || recommendation.status === 'deferred';
  const actionCompletable = recommendation.status === 'accepted';
  const completionBlockedByTasks = actionCompletable && recommendation.has_pending_tasks;

  const blockedReason = t('meetings.recommendation.messages.invalid_transition');

  const run = async (
    action: TransitionAction,
    successKey: string,
    call: () => Promise<unknown>,
  ) => {
    setBusy(action);
    try {
      await call();
      showToast('success', t(successKey));
      onChanged?.();
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred');
      showToast('error', msg);
    } finally {
      setBusy(null);
    }
  };

  const runApprove = () =>
    run('approve', 'meetings.recommendation.messages.approved', () =>
      recommendationsApi.approve(recommendation.id),
    );

  const runAccept = () =>
    run('accept', 'meetings.recommendation.messages.accepted', () =>
      recommendationsApi.accept(recommendation.id),
    );

  const runReject = () =>
    run('reject', 'meetings.recommendation.messages.rejected', () =>
      recommendationsApi.reject(recommendation.id),
    );

  const runComplete = () =>
    run('complete', 'meetings.recommendation.messages.completed_msg', () =>
      recommendationsApi.complete(recommendation.id),
    );

  const iconKind = isRuling ? IconClipboardCheck : IconClipboardList;
  const KindIcon = iconKind;

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <KindIcon className="h-4 w-4 text-[var(--accent-default)]" />
              <span className="font-mono text-xs text-[var(--text-tertiary)]">
                {recommendation.reference_number}
              </span>
              <StatusBadge
                type="custom"
                status={recommendation.kind}
                label={t(`meetings.recommendation.kinds.${recommendation.kind}`, {
                  defaultValue: isRuling ? 'قرار' : 'إجراء',
                })}
                color={isRuling ? 'info' : 'warning'}
                size="sm"
              />
              <StatusBadge
                type="custom"
                status={recommendation.status}
                label={
                  t(`meetings.recommendation.statuses.${recommendation.status}`) ||
                  recommendation.status_label
                }
                color={STATUS_COLOR[recommendation.status] ?? 'secondary'}
                size="sm"
              />
              {!isRuling && recommendation.priority && (
                <StatusBadge
                  type="custom"
                  status={recommendation.priority}
                  label={t(`meetings.recommendation.priorities.${recommendation.priority}`)}
                  color={PRIORITY_COLOR[recommendation.priority] ?? 'secondary'}
                  size="sm"
                />
              )}
            </div>
            <h4 className="text-sm font-semibold text-[var(--text-primary)]">
              {recommendation.title}
            </h4>
            {recommendation.description && (
              <p className="line-clamp-2 text-xs text-[var(--text-secondary)]">
                {recommendation.description}
              </p>
            )}
          </div>
        </div>

        <dl className="grid grid-cols-1 gap-2 text-xs text-[var(--text-secondary)] sm:grid-cols-2">
          <RulingFacts recommendation={recommendation} isRuling={isRuling} t={t} />
        </dl>

        {recommendation.defer_reason && (
          <Alert variant="warning" icon={<IconAlertTriangle className="h-4 w-4" />}>
            <div className="flex flex-col gap-1">
              <span className="text-xs font-medium">
                {t('meetings.recommendation.defer.banner_title', { defaultValue: 'مؤجل' })}
              </span>
              <span className="text-xs">{recommendation.defer_reason}</span>
              {recommendation.deferred_until && (
                <span className="text-xs text-[var(--text-tertiary)]">
                  {t('meetings.recommendation.defer.until_label', {
                    defaultValue: 'تاريخ الاستئناف',
                  })}
                  : <span className="tabular-nums">{recommendation.deferred_until}</span>
                </span>
              )}
            </div>
          </Alert>
        )}

        {recommendation.is_overdue && !isRuling && (
          <Badge variant="danger" size="sm">
            {t('meetings.recommendation.fields.overdue_badge')}
          </Badge>
        )}

        <div className="flex flex-wrap items-center gap-2 pt-1">
          {isRuling ? (
            <>
              <Tooltip content={tooltipFor(rulingActionable && canApprove, blockedReason)}>
                <Button
                  size="sm"
                  variant="primary"
                  leftIcon={<IconCheck className="h-4 w-4" />}
                  disabled={!rulingActionable || !canApprove}
                  loading={busy === 'approve'}
                  onClick={runApprove}
                >
                  {t('meetings.recommendation.actions.approve', { defaultValue: 'اعتماد' })}
                </Button>
              </Tooltip>
              <Tooltip content={tooltipFor(rulingActionable && canReject, blockedReason)}>
                <Button
                  size="sm"
                  variant="danger"
                  leftIcon={<IconX className="h-4 w-4" />}
                  disabled={!rulingActionable || !canReject}
                  loading={busy === 'reject'}
                  onClick={runReject}
                >
                  {t('meetings.recommendation.actions.reject')}
                </Button>
              </Tooltip>
              <Tooltip content={tooltipFor(rulingActionable && canDefer, blockedReason)}>
                <Button
                  size="sm"
                  variant="outline"
                  leftIcon={<IconClock className="h-4 w-4" />}
                  disabled={!rulingActionable || !canDefer}
                  onClick={() => setShowDefer(true)}
                >
                  {t('meetings.recommendation.actions.defer')}
                </Button>
              </Tooltip>
            </>
          ) : (
            <ActionItemActions
              actionAcceptable={actionProposeable}
              actionCompletable={actionCompletable}
              completionBlockedByTasks={completionBlockedByTasks}
              canAccept={canAccept}
              canReject={canReject}
              canDefer={canDefer}
              canComplete={canComplete}
              busy={busy}
              blockedReason={blockedReason}
              onAccept={runAccept}
              onReject={runReject}
              onComplete={runComplete}
              onDefer={() => setShowDefer(true)}
              t={t}
            />
          )}
        </div>

        {completionBlockedByTasks && (
          <p className="text-xs text-[var(--status-danger)]">
            <IconAlertTriangle className="me-1 inline h-3 w-3" />
            {t('meetings.recommendation.completion_blocked', {
              defaultValue: 'لا يمكن الإنجاز قبل إغلاق المهام المرتبطة',
            })}
          </p>
        )}
      </CardContent>

      <DeferModal
        open={showDefer}
        recommendationId={recommendation.id}
        onClose={() => setShowDefer(false)}
        onDeferred={onChanged}
      />
    </Card>
  );
};

interface RulingFactsProps {
  recommendation: Recommendation;
  isRuling: boolean;
  t: (key: string, options?: Record<string, unknown>) => string;
}

const RulingFacts: React.FC<RulingFactsProps> = ({ recommendation, isRuling, t }) => {
  if (isRuling) {
    return (
      <>
        {recommendation.type && (
          <div>
            <dt className="text-[var(--text-tertiary)]">
              {t('strategy.decisions.fields.type', { defaultValue: 'نوع القرار' })}
            </dt>
            <dd>{t(`strategy.decisions.types.${recommendation.type}`)}</dd>
          </div>
        )}
        {recommendation.requester && (
          <div>
            <dt className="text-[var(--text-tertiary)]">
              {t('strategy.decisions.fields.requester', { defaultValue: 'مقدّم الطلب' })}
            </dt>
            <dd>{recommendation.requester.name}</dd>
          </div>
        )}
      </>
    );
  }
  return (
    <>
      {recommendation.assignee && (
        <div>
          <dt className="text-[var(--text-tertiary)]">
            {t('meetings.recommendation.fields.assignee')}
          </dt>
          <dd>{recommendation.assignee.name}</dd>
        </div>
      )}
      {recommendation.due_date && (
        <div>
          <dt className="text-[var(--text-tertiary)]">
            {t('meetings.recommendation.fields.due_date')}
          </dt>
          <dd className="tabular-nums">{recommendation.due_date}</dd>
        </div>
      )}
    </>
  );
};

interface ActionItemActionsProps {
  actionAcceptable: boolean;
  actionCompletable: boolean;
  completionBlockedByTasks: boolean;
  canAccept: boolean;
  canReject: boolean;
  canDefer: boolean;
  canComplete: boolean;
  busy: TransitionAction | null;
  blockedReason: string;
  onAccept: () => void;
  onReject: () => void;
  onComplete: () => void;
  onDefer: () => void;
  t: (key: string, options?: Record<string, unknown>) => string;
}

const ActionItemActions: React.FC<ActionItemActionsProps> = ({
  actionAcceptable,
  actionCompletable,
  completionBlockedByTasks,
  canAccept,
  canReject,
  canDefer,
  canComplete,
  busy,
  blockedReason,
  onAccept,
  onReject,
  onComplete,
  onDefer,
  t,
}) => {
  const completeTip = completionBlockedByTasks
    ? t('meetings.recommendation.actions.complete_blocked_by_tasks', {
        defaultValue: 'توجد مهام معلّقة مرتبطة بهذا الإجراء',
      })
    : tooltipFor(actionCompletable && canComplete, blockedReason);

  return (
    <>
      <Tooltip content={tooltipFor(actionAcceptable && canAccept, blockedReason)}>
        <Button
          size="sm"
          variant="primary"
          leftIcon={<IconCheck className="h-4 w-4" />}
          disabled={!actionAcceptable || !canAccept}
          loading={busy === 'accept'}
          onClick={onAccept}
        >
          {t('meetings.recommendation.actions.accept')}
        </Button>
      </Tooltip>
      <Tooltip content={tooltipFor(actionAcceptable && canReject, blockedReason)}>
        <Button
          size="sm"
          variant="danger"
          leftIcon={<IconX className="h-4 w-4" />}
          disabled={!actionAcceptable || !canReject}
          loading={busy === 'reject'}
          onClick={onReject}
        >
          {t('meetings.recommendation.actions.reject')}
        </Button>
      </Tooltip>
      <Tooltip content={tooltipFor(actionAcceptable && canDefer, blockedReason)}>
        <Button
          size="sm"
          variant="outline"
          leftIcon={<IconClock className="h-4 w-4" />}
          disabled={!actionAcceptable || !canDefer}
          onClick={onDefer}
        >
          {t('meetings.recommendation.actions.defer')}
        </Button>
      </Tooltip>
      <Tooltip content={completeTip}>
        <Button
          size="sm"
          leftIcon={<IconCircleCheck className="h-4 w-4" />}
          disabled={!actionCompletable || !canComplete || completionBlockedByTasks}
          loading={busy === 'complete'}
          onClick={onComplete}
        >
          {t('meetings.recommendation.actions.complete')}
        </Button>
      </Tooltip>
    </>
  );
};

export default RecommendationCard;