import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Button,
  Modal,
  ModalBody,
  ModalFooter,
  ModalHeader,
  Textarea,
} from '@shared/ui';
import { IconPlus, IconTrash } from '@shared/ui/icons';
import { resolutionsApi } from './api';
import type { MeetingResolution, PlannedTaskPayload } from './types';

export interface ConvertToTasksModalProps {
  open: boolean;
  resolution: MeetingResolution;
  onClose: () => void;
  onConverted?: (response: { resolution: MeetingResolution; tasks: Array<{ id: number }> }) => void;
}

interface DraftTask extends PlannedTaskPayload {
  /** Stable key for the React list — does NOT round-trip to the API. */
  _key: string;
}

const blankTask = (): DraftTask => ({
  _key: `t-${Math.random().toString(36).slice(2, 10)}`,
  title: '',
  description: '',
  assignee_id: 0,
  due_date: '',
  priority: 'medium',
  project_id: null,
});

/**
 * Phase 3 / Direction R — convert-to-tasks modal.
 *
 * The user types one or more tasks, each with title + assignee_id (required),
 * plus optional description / due_date / priority / project_id. On submit the
 * modal POSTs to `/api/meeting-resolutions/{id}/convert-to-tasks`. The server
 * returns the freshly created tasks + the updated resolution; the parent
 * uses these to refresh the card.
 *
 * NO approve/reject/adopt verbs anywhere in this file.
 */
const ConvertToTasksModal: React.FC<ConvertToTasksModalProps> = ({
  open,
  resolution,
  onClose,
  onConverted,
}) => {
  const { t } = useTranslation();
  const [tasks, setTasks] = useState<DraftTask[]>([blankTask()]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const addRow = () => setTasks((prev) => [...prev, blankTask()]);
  const removeRow = (key: string) =>
    setTasks((prev) => (prev.length === 1 ? prev : prev.filter((t) => t._key !== key)));
  const updateRow = (key: string, patch: Partial<PlannedTaskPayload>) =>
    setTasks((prev) => prev.map((t) => (t._key === key ? { ...t, ...patch } : t)));

  const submit = async () => {
    setError(null);
    if (tasks.length === 0) {
      setError('يجب إدخال مهمة واحدة على الأقل');
      return;
    }
    const incomplete = tasks.find((t) => !t.title.trim() || !t.assignee_id);
    if (incomplete) {
      setError('كل مهمة تحتاج عنوانًا ومسؤولًا');
      return;
    }
    setSubmitting(true);
    try {
      const payload = {
        tasks: tasks.map((t) => ({
          title: t.title.trim(),
          description: t.description?.trim() || null,
          assignee_id: t.assignee_id,
          due_date: t.due_date || null,
          priority: t.priority,
          project_id: t.project_id ?? null,
        })),
      };
      const response = await resolutionsApi.convertToTasks(resolution.id, payload);
      onConverted?.(response);
      onClose();
    } catch (e) {
      const msg = (e as Error).message || 'فشل التحويل';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} size="lg">
      <ModalHeader>
        {t('meetings.resolution.convert_to_tasks.title', {
          defaultValue: 'تحويل المخرج إلى مهام',
        })}
      </ModalHeader>
      <ModalBody>
        {error && (
          <Alert variant="danger" className="mb-3">
            {error}
          </Alert>
        )}

        <p className="mb-3 text-sm text-[var(--text-secondary)]">
          {t('meetings.resolution.convert_to_tasks.hint', {
            defaultValue:
              'سيتم إنشاء المهام في جدول المهام وربطها بهذا المخرج. لا يمكن التحويل مرتين.',
          })}
        </p>

        <div className="space-y-3">
          {tasks.map((task, idx) => (
            <div
              key={task._key}
              className="rounded border border-[var(--surface-border)] p-3"
              data-testid={`convert-task-row-${idx}`}
            >
              <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium text-[var(--text-tertiary)]">
                  {t('meetings.resolution.convert_to_tasks.row_label', {
                    defaultValue: 'مهمة #{{n}}',
                    n: idx + 1,
                  })}
                </span>
                {tasks.length > 1 && (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => removeRow(task._key)}
                    leftIcon={<IconTrash className="h-3 w-3" />}
                    data-testid={`convert-task-remove-${idx}`}
                  >
                    {t('common.remove', { defaultValue: 'حذف' })}
                  </Button>
                )}
              </div>

              <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                <label className="text-xs">
                  <span className="mb-1 block text-[var(--text-secondary)]">
                    {t('meetings.resolution.form.title_label', { defaultValue: 'العنوان' })}
                    <span className="ms-1 text-[var(--status-danger)]">*</span>
                  </span>
                  <input
                    type="text"
                    value={task.title}
                    onChange={(e) => updateRow(task._key, { title: e.target.value })}
                    className="w-full rounded border border-[var(--surface-border)] bg-[var(--surface-base)] px-2 py-1 text-sm"
                    data-testid={`convert-task-title-${idx}`}
                  />
                </label>

                <label className="text-xs">
                  <span className="mb-1 block text-[var(--text-secondary)]">
                    {t('meetings.resolution.form.owner_label', { defaultValue: 'المسؤول' })}
                    <span className="ms-1 text-[var(--status-danger)]">*</span>
                  </span>
                  <input
                    type="number"
                    value={task.assignee_id || ''}
                    onChange={(e) => updateRow(task._key, { assignee_id: Number(e.target.value) })}
                    className="w-full rounded border border-[var(--surface-border)] bg-[var(--surface-base)] px-2 py-1 text-sm"
                    data-testid={`convert-task-assignee-${idx}`}
                    placeholder="user_id"
                  />
                </label>

                <label className="text-xs">
                  <span className="mb-1 block text-[var(--text-secondary)]">
                    {t('meetings.resolution.form.due_date_label', {
                      defaultValue: 'تاريخ الاستحقاق',
                    })}
                  </span>
                  <input
                    type="date"
                    value={task.due_date || ''}
                    onChange={(e) => updateRow(task._key, { due_date: e.target.value })}
                    className="w-full rounded border border-[var(--surface-border)] bg-[var(--surface-base)] px-2 py-1 text-sm"
                    data-testid={`convert-task-due-${idx}`}
                  />
                </label>

                <label className="text-xs">
                  <span className="mb-1 block text-[var(--text-secondary)]">
                    {t('meetings.resolution.form.priority_label', {
                      defaultValue: 'الأولوية',
                    })}
                  </span>
                  <select
                    value={task.priority}
                    onChange={(e) =>
                      updateRow(task._key, {
                        priority: e.target.value as 'low' | 'medium' | 'high' | 'critical',
                      })
                    }
                    className="w-full rounded border border-[var(--surface-border)] bg-[var(--surface-base)] px-2 py-1 text-sm"
                    data-testid={`convert-task-priority-${idx}`}
                  >
                    <option value="low">{t('meetings.resolution.priorities.low', { defaultValue: 'منخفضة' })}</option>
                    <option value="medium">{t('meetings.resolution.priorities.medium', { defaultValue: 'متوسطة' })}</option>
                    <option value="high">{t('meetings.resolution.priorities.high', { defaultValue: 'عالية' })}</option>
                    <option value="critical">{t('meetings.resolution.priorities.critical', { defaultValue: 'حرجة' })}</option>
                  </select>
                </label>

                <label className="text-xs md:col-span-2">
                  <span className="mb-1 block text-[var(--text-secondary)]">
                    {t('meetings.resolution.form.description_label', {
                      defaultValue: 'الوصف',
                    })}
                  </span>
                  <Textarea
                    value={task.description ?? ''}
                    onChange={(e) =>
                      updateRow(task._key, { description: e.target.value })
                    }
                    rows={2}
                    data-testid={`convert-task-description-${idx}`}
                  />
                </label>
              </div>
            </div>
          ))}

          <Button
            variant="outline"
            size="sm"
            onClick={addRow}
            leftIcon={<IconPlus className="h-4 w-4" />}
            data-testid="convert-task-add"
          >
            {t('meetings.resolution.convert_to_tasks.add_row', {
              defaultValue: 'إضافة مهمة',
            })}
          </Button>
        </div>
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          {t('meetings.resolution.form.cancel', { defaultValue: 'إلغاء' })}
        </Button>
        <Button
          onClick={submit}
          loading={submitting}
          data-testid="convert-task-submit"
        >
          {t('meetings.resolution.convert_to_tasks.submit', {
            defaultValue: 'تحويل إلى مهام',
          })}
        </Button>
      </ModalFooter>
    </Modal>
  );
};

export default ConvertToTasksModal;