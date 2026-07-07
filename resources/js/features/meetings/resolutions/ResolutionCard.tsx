import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  DatePicker,
  Modal,
  ModalBody,
  ModalFooter,
  ModalHeader,
  StatusBadge,
  Textarea,
  type CustomBadgeColor,
} from '@shared/ui';
import {
  IconAlertTriangle,
  IconCircleCheck,
  IconClipboardCheck,
  IconClipboardList,
  IconClock,
  IconEdit,
  IconFlag,
  IconLayoutKanban,
  IconPlayerPlay,
  IconTrash,
  IconX,
} from '@shared/ui/icons';
import { useToast } from '@shared/ui/Toast';
import { resolutionsApi } from './api';
import ConvertToTasksModal from './ConvertToTasksModal';
import type { MeetingResolution } from './types';

export interface ResolutionCardProps {
  resolution: MeetingResolution;
  permissions: {
    canUpdate: boolean;
    canDelete: boolean;
    canStart: boolean;
    canHold: boolean;
    canReleaseHold: boolean;
    canConvertToTasks: boolean;
    canComplete: boolean;
    canCancel: boolean;
  };
  onChanged?: () => void;
  onEdit?: (resolution: MeetingResolution) => void;
}

const STATUS_COLOR: Record<string, CustomBadgeColor> = {
  open: 'info',
  in_progress: 'warning',
  converted_to_tasks: 'success',
  completed: 'success',
  cancelled: 'secondary',
};

const PRIORITY_COLOR: Record<string, CustomBadgeColor> = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

type LifecycleAction =
  | 'start'
  | 'hold'
  | 'release-hold'
  | 'convert-to-tasks'
  | 'complete'
  | 'cancel'
  | 'edit'
  | 'delete';

const isTerminal = (status: MeetingResolution['status']): boolean =>
  status === 'completed' ||
  status === 'cancelled' ||
  status === 'converted_to_tasks';

const isOverdue = (r: MeetingResolution): boolean => {
  if (isTerminal(r.status)) return false;
  if (!r.due_date) return false;
  // due_date comes back as YYYY-MM-DD or ISO; compare via Date.parse.
  const t = Date.parse(r.due_date);
  if (Number.isNaN(t)) return false;
  return t < Date.now();
};

const tooltipFor = (allowed: boolean, blockedReason: string): string =>
  allowed ? '' : blockedReason;

const ResolutionCard: React.FC<ResolutionCardProps> = ({
  resolution,
  permissions,
  onChanged,
  onEdit,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [busy, setBusy] = useState<LifecycleAction | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [showHold, setShowHold] = useState(false);
  const [holdReason, setHoldReason] = useState('');
  const [showConvert, setShowConvert] = useState(false);
  const [holdUntil, setHoldUntil] = useState('');

  const isDecision = resolution.kind === 'decision';
  const KindIcon = isDecision ? IconClipboardCheck : IconClipboardList;
  const terminal = isTerminal(resolution.status);
  const active = resolution.status === 'open' || resolution.status === 'in_progress';
  const onHold = Boolean(resolution.is_on_hold);
  const overdue = isOverdue(resolution);
  const blockedReason = t('meetings.resolution.messages.invalid_transition', {
    defaultValue: 'لا يمكن تنفيذ هذا الإجراء في الحالة الحالية',
  });

  const run = async (
    action: LifecycleAction,
    successKey: string,
    successDefault: string,
    call: () => Promise<unknown>,
  ) => {
    setBusy(action);
    try {
      await call();
      showToast('success', t(successKey, { defaultValue: successDefault }));
      onChanged?.();
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred', {
        defaultValue: 'حدث خطأ',
      });
      showToast('error', msg);
    } finally {
      setBusy(null);
    }
  };

  const runStart = () =>
    run(
      'start',
      'meetings.resolution.messages.started',
      'تم بدء التنفيذ',
      () => resolutionsApi.start(resolution.id),
    );

  const runReleaseHold = () =>
    run(
      'release-hold',
      'meetings.resolution.messages.released',
      'تم رفع التعليق',
      () => resolutionsApi.releaseHold(resolution.id),
    );

  const runComplete = () =>
    run(
      'complete',
      'meetings.resolution.messages.completed',
      'تم إغلاق القرار',
      () => resolutionsApi.complete(resolution.id),
    );

  const runCancel = () =>
    run(
      'cancel',
      'meetings.resolution.messages.cancelled',
      'تم إلغاء القرار',
      () => resolutionsApi.cancel(resolution.id),
    );

  const runDelete = () => {
    setBusy('delete');
    resolutionsApi
      .remove(resolution.id)
      .then(() => {
        showToast(
          'success',
          t('meetings.resolution.messages.deleted', { defaultValue: 'تم الحذف' }),
        );
        setConfirmDelete(false);
        onChanged?.();
      })
      .catch((err: unknown) => {
        const msg =
          err instanceof Error
            ? err.message
            : t('common.error_occurred', { defaultValue: 'حدث خطأ' });
        showToast('error', msg);
      })
      .finally(() => setBusy(null));
  };

  const openConvertModal = () => {
    setShowConvert(true);
  };

  const submitHold = () => {
    const trimmed = holdReason.trim();
    if (trimmed.length === 0) {
      showToast(
        'error',
        t('meetings.resolution.hold.reason_required', {
          defaultValue: 'سبب التعليق مطلوب',
        }),
      );
      return;
    }
    setBusy('hold');
    resolutionsApi
      .hold(resolution.id, {
        hold_reason: trimmed,
        hold_until: holdUntil || null,
      })
      .then(() => {
        showToast(
          'success',
          t('meetings.resolution.messages.held', { defaultValue: 'تم تعليق القرار' }),
        );
        setShowHold(false);
        setHoldReason('');
        setHoldUntil('');
        onChanged?.();
      })
      .catch((err: unknown) => {
        const msg =
          err instanceof Error
            ? err.message
            : t('common.error_occurred', { defaultValue: 'حدث خطأ' });
        showToast('error', msg);
      })
      .finally(() => setBusy(null));
  };

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <KindIcon className="h-4 w-4 text-[var(--accent-default)]" />
              {resolution.reference_number && (
                <span className="font-mono text-xs text-[var(--text-tertiary)]">
                  {resolution.reference_number}
                </span>
              )}
              <StatusBadge
                type="custom"
                status={resolution.kind}
                label={
                  resolution.kind_label ??
                  t(`meetings.resolution.kind.${resolution.kind}`, {
                    defaultValue: isDecision ? 'قرار' : 'توصية',
                  })
                }
                color={isDecision ? 'info' : 'warning'}
                size="sm"
              />
              <StatusBadge
                type="custom"
                status={resolution.status}
                label={
                  t(`meetings.resolution.statuses.${resolution.status}`) ||
                  resolution.status_label
                }
                color={STATUS_COLOR[resolution.status] ?? 'secondary'}
                size="sm"
              />
              {resolution.priority && (
                <StatusBadge
                  type="custom"
                  status={resolution.priority}
                  label={
                    resolution.priority_label ??
                    t(`meetings.resolution.priorities.${resolution.priority}`)
                  }
                  color={PRIORITY_COLOR[resolution.priority] ?? 'secondary'}
                  size="sm"
                />
              )}
              {onHold && (
                <Badge variant="warning" size="sm">
                  {t('meetings.resolution.hold.badge', { defaultValue: 'معلّق' })}
                </Badge>
              )}
              {overdue && (
                <Badge variant="danger" size="sm">
                  {t('meetings.resolution.fields.overdue_badge', {
                    defaultValue: 'متأخر',
                  })}
                </Badge>
              )}
            </div>
            <h4 className="text-sm font-semibold text-[var(--text-primary)]">
              {resolution.title}
            </h4>
            {resolution.description && (
              <p className="line-clamp-2 text-xs text-[var(--text-secondary)]">
                {resolution.description}
              </p>
            )}
          </div>
        </div>

        <dl className="grid grid-cols-1 gap-2 text-xs text-[var(--text-secondary)] sm:grid-cols-3">
          {resolution.owner && (
            <div>
              <dt className="text-[var(--text-tertiary)]">
                {t('meetings.resolution.fields.owner', { defaultValue: 'المالك' })}
              </dt>
              <dd>{resolution.owner.name}</dd>
            </div>
          )}
          {resolution.meeting && (
            <div>
              <dt className="text-[var(--text-tertiary)]">
                {t('meetings.resolution.fields.meeting', { defaultValue: 'الاجتماع' })}
              </dt>
              <dd className="truncate">
                {resolution.meeting.reference_number} — {resolution.meeting.title}
              </dd>
            </div>
          )}
          {resolution.due_date && (
            <div>
              <dt className="text-[var(--text-tertiary)]">
                {t('meetings.resolution.fields.due_date', { defaultValue: 'تاريخ الاستحقاق' })}
              </dt>
              <dd
                className={
                  overdue
                    ? 'tabular-nums text-[var(--status-danger)]'
                    : 'tabular-nums'
                }
              >
                {resolution.due_date}
              </dd>
            </div>
          )}
        </dl>

        {resolution.links && resolution.links.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-[var(--text-secondary)]">
            <IconFlag className="h-3 w-3 text-[var(--text-tertiary)]" />
            {resolution.links.map((link) => (
              <span
                key={link.id}
                className="inline-flex items-center gap-1 rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-[11px]"
              >
                <span className="text-[var(--text-tertiary)]">
                  {t(`meetings.resolution.linkable_types.${link.linkable_type}`, {
                    defaultValue: link.linkable_type === 'project' ? 'مشروع' : 'مخاطرة',
                  })}
                </span>
                <span className="tabular-nums">#{link.linkable_id}</span>
                {link.linkable_label && (
                  <span className="text-[var(--text-tertiary)]">— {link.linkable_label}</span>
                )}
                <span className="text-[var(--text-tertiary)]">·</span>
                <span>
                  {t(`meetings.resolution.link_roles.${link.link_role}`, {
                    defaultValue:
                      link.link_role === 'related_to' ? 'مرتبط بـ' : 'نطاق التنفيذ',
                  })}
                </span>
              </span>
            ))}
          </div>
        )}

        {onHold && resolution.hold_reason && (
          <Alert variant="warning" icon={<IconAlertTriangle className="h-4 w-4" />}>
            <div className="flex flex-col gap-1">
              <span className="text-xs font-medium">
                {t('meetings.resolution.hold.banner_title', { defaultValue: 'القرار معلّق' })}
              </span>
              <span className="text-xs">{resolution.hold_reason}</span>
              {resolution.hold_until && (
                <span className="text-xs text-[var(--text-tertiary)]">
                  {t('meetings.resolution.hold.until_label', {
                    defaultValue: 'تاريخ الاستئناف',
                  })}
                  : <span className="tabular-nums">{resolution.hold_until}</span>
                </span>
              )}
              {resolution.holder && (
                <span className="text-xs text-[var(--text-tertiary)]">
                  {t('meetings.resolution.hold.by_label', { defaultValue: 'بواسطة' })}:{' '}
                  {resolution.holder.name}
                </span>
              )}
            </div>
          </Alert>
        )}

        {/* Phase 3: task-progress indicator (only when this resolution
            has spawned tasks). completion_percentage lives on the server
            and stays at 0 when tasks_count is 0, so the indicator only
            renders meaningful content when tasks exist. */}
        {(resolution.tasks_count ?? 0) > 0 && (
          <div
            className="rounded border border-[var(--surface-border)] bg-[var(--surface-muted)] p-2"
            data-testid="tasks-progress"
          >
            <div className="mb-1 flex items-center justify-between text-xs text-[var(--text-secondary)]">
              <span>
                {t('meetings.resolution.tasks_progress.label', {
                  defaultValue: 'المهام',
                })}
              </span>
              <span className="tabular-nums">
                {resolution.completed_tasks_count ?? 0} / {resolution.tasks_count ?? 0}
              </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[var(--surface-base)]">
              <div
                className="h-full rounded-full bg-[var(--status-success)] transition-[width]"
                style={{ width: `${Math.min(100, resolution.completion_percentage ?? 0)}%` }}
                data-testid="tasks-progress-bar"
              />
            </div>
            <div className="mt-1 text-[10px] tabular-nums text-[var(--text-tertiary)]">
              {Math.round(resolution.completion_percentage ?? 0)}%
              {' · '}
              {t('meetings.resolution.tasks_progress.pending', {
                defaultValue: '{{n}} قيد التنفيذ',
                n: resolution.pending_tasks_count ?? 0,
              })}
            </div>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2 pt-1">
          {/* Start */}
          {resolution.status === 'open' && permissions.canStart && (
            <Button
              size="sm"
              variant="primary"
              leftIcon={<IconPlayerPlay className="h-4 w-4" />}
              loading={busy === 'start'}
              onClick={runStart}
            >
              {t('meetings.resolution.actions.start', { defaultValue: 'بدء التنفيذ' })}
            </Button>
          )}

          {/* Hold / Release hold */}
          {active && !onHold && permissions.canHold && (
            <Button
              size="sm"
              variant="outline"
              leftIcon={<IconClock className="h-4 w-4" />}
              loading={busy === 'hold'}
              onClick={() => setShowHold(true)}
            >
              {t('meetings.resolution.actions.hold', { defaultValue: 'تعليق' })}
            </Button>
          )}
          {active && onHold && permissions.canReleaseHold && (
            <Button
              size="sm"
              variant="outline"
              leftIcon={<IconClock className="h-4 w-4" />}
              loading={busy === 'release-hold'}
              onClick={runReleaseHold}
            >
              {t('meetings.resolution.actions.release_hold', {
                defaultValue: 'رفع التعليق',
              })}
            </Button>
          )}

          {/* Convert to tasks — only when not already converted */}
          {active && permissions.canConvertToTasks && resolution.status !== 'converted_to_tasks' && (
            <Button
              size="sm"
              variant="secondary"
              leftIcon={<IconLayoutKanban className="h-4 w-4" />}
              onClick={openConvertModal}
              data-testid="convert-to-tasks-btn"
            >
              {t('meetings.resolution.actions.convert_to_tasks', {
                defaultValue: 'تحويل إلى مهام',
              })}
            </Button>
          )}

          {/* Complete */}
          {active && permissions.canComplete && (
            <Button
              size="sm"
              leftIcon={<IconCircleCheck className="h-4 w-4" />}
              loading={busy === 'complete'}
              onClick={runComplete}
              title={tooltipFor(true, blockedReason)}
            >
              {t('meetings.resolution.actions.complete', { defaultValue: 'إغلاق' })}
            </Button>
          )}

          {/* Cancel */}
          {active && permissions.canCancel && (
            <Button
              size="sm"
              variant="danger"
              leftIcon={<IconX className="h-4 w-4" />}
              loading={busy === 'cancel'}
              onClick={runCancel}
            >
              {t('meetings.resolution.actions.cancel', { defaultValue: 'إلغاء' })}
            </Button>
          )}

          {/* Edit */}
          {permissions.canUpdate && onEdit && !terminal && (
            <Button
              size="sm"
              variant="ghost"
              leftIcon={<IconEdit className="h-4 w-4" />}
              onClick={() => onEdit(resolution)}
            >
              {t('common.edit', { defaultValue: 'تعديل' })}
            </Button>
          )}

          {/* Delete */}
          {permissions.canDelete && (
            <Button
              size="sm"
              variant="ghost"
              leftIcon={<IconTrash className="h-4 w-4" />}
              onClick={() => setConfirmDelete(true)}
            >
              {t('common.delete', { defaultValue: 'حذف' })}
            </Button>
          )}
        </div>
      </CardContent>

      {/* Hold modal */}
      <Modal open={showHold} onClose={() => setShowHold(false)} size="md">
        <ModalHeader onClose={() => setShowHold(false)}>
          <div className="flex items-center gap-2">
            <IconClock className="h-5 w-5 text-[var(--accent-default)]" />
            <h2 className="text-lg font-semibold text-[var(--text-primary)]">
              {t('meetings.resolution.hold.title', { defaultValue: 'تعليق القرار' })}
            </h2>
          </div>
        </ModalHeader>
        <ModalBody className="space-y-4">
          <Textarea
            label={t('meetings.resolution.hold.reason_label', {
              defaultValue: 'سبب التعليق',
            })}
            value={holdReason}
            onChange={(e) => setHoldReason(e.target.value)}
            rows={3}
            required
            placeholder={t('meetings.resolution.hold.reason_placeholder', {
              defaultValue: 'لماذا يتم تعليق هذا القرار؟',
            })}
          />
          <DatePicker
            label={t('meetings.resolution.hold.until_label', {
              defaultValue: 'تاريخ الاستئناف (اختياري)',
            })}
            value={holdUntil}
            onChange={setHoldUntil}
          />
        </ModalBody>
        <ModalFooter>
          <Button
            variant="outline"
            onClick={() => setShowHold(false)}
            disabled={busy === 'hold'}
          >
            {t('common.cancel', { defaultValue: 'إلغاء' })}
          </Button>
          <Button
            onClick={submitHold}
            loading={busy === 'hold'}
            leftIcon={<IconClock className="h-4 w-4" />}
          >
            {t('meetings.resolution.actions.hold', { defaultValue: 'تعليق' })}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Delete confirm */}
      <Modal open={confirmDelete} onClose={() => setConfirmDelete(false)} size="sm">
        <ModalHeader onClose={() => setConfirmDelete(false)}>
          <h2 className="text-lg font-semibold text-[var(--text-primary)]">
            {t('meetings.resolution.delete_confirm.title', {
              defaultValue: 'تأكيد الحذف',
            })}
          </h2>
        </ModalHeader>
        <ModalBody>
          <p className="text-sm text-[var(--text-secondary)]">
            {t('meetings.resolution.delete_confirm.body', {
              defaultValue: 'هل تريد حذف هذا القرار نهائيًا؟ لا يمكن التراجع.',
            })}
          </p>
        </ModalBody>
        <ModalFooter>
          <Button
            variant="outline"
            onClick={() => setConfirmDelete(false)}
            disabled={busy === 'delete'}
          >
            {t('common.cancel', { defaultValue: 'إلغاء' })}
          </Button>
          <Button
            variant="danger"
            onClick={runDelete}
            loading={busy === 'delete'}
            leftIcon={<IconTrash className="h-4 w-4" />}
          >
            {t('common.delete', { defaultValue: 'حذف' })}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Phase 3: convert-to-tasks modal */}
      <ConvertToTasksModal
        open={showConvert}
        resolution={resolution}
        onClose={() => setShowConvert(false)}
        onConverted={() => {
          setShowConvert(false);
          showToast(
            'success',
            t('meetings.resolution.messages.converted', {
              defaultValue: 'تم تحويل المخرج إلى مهام',
            }),
          );
          onChanged?.();
        }}
      />
    </Card>
  );
};

export default ResolutionCard;