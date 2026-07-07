import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { DatePicker } from '@shared/ui/DatePicker';
import { DurationChips, TASK_DURATIONS } from '@shared/ui/DurationChips';
import {IconClipboardList, IconPlus, IconTrash} from '@tabler/icons-react';
import { taskPriorityOptions } from './constants';
import type { TaskItem, MilestoneItem, UserOption } from './types';

interface InlineTasksEditorProps {
  tasks: TaskItem[];
  milestones: MilestoneItem[];
  users: UserOption[];
  projectStartDate: string;
  projectEndDate: string;
  onTaskChange: (index: number, field: keyof TaskItem, value: string | number | undefined) => void;
  onAddTask: () => void;
  onRemoveTask: (index: number) => void;
  onSelectAssignee: (index: number, userId: number) => void;
  compact?: boolean;
}

const InlineTasksEditor = memo<InlineTasksEditorProps>(({
  tasks,
  milestones,
  users,
  projectStartDate,
  projectEndDate,
  onTaskChange,
  onAddTask,
  onRemoveTask,
  onSelectAssignee,
  compact = false,
}) => {
  const { t } = useTranslation();
  const hasDateRange = projectStartDate && projectEndDate;

  const translatedTaskPriorityOptions = taskPriorityOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));
  const userOptions = users.map((u) => ({ value: String(u.id), label: u.name }));

  // خيارات المراحل للقائمة المنسدلة
  const milestoneOptions = [
    { value: '', label: t('projects.no_milestone') },
    ...milestones
      .map((m, fullIndex) => ({ m, fullIndex }))
      .filter(({ m }) => m.name.trim() !== '')
      .map(({ m, fullIndex }) => ({
        // Use the FULL milestones[] index so it matches the backend milestoneIds map.
        value: fullIndex.toString(),
        label: m.name,
      })),
  ];

  // الحصول على نطاق تواريخ المرحلة المحددة
  const getMilestoneDateRange = (milestoneIndex?: number) => {
    if (milestoneIndex === undefined || !milestones[milestoneIndex]) {
      return { minDate: projectStartDate, maxDate: projectEndDate };
    }
    const milestone = milestones[milestoneIndex];
    return {
      minDate: milestone.start_date || projectStartDate,
      maxDate: milestone.due_date || projectEndDate,
    };
  };

  const content = (
    <>
      {hasDateRange ? (
        <div className={`${compact ? 'mb-3 p-2' : 'mb-4 p-3'} border border-[var(--border-default)] bg-[var(--surface-base)] rounded-lg`}>
          <p className="text-xs text-[var(--text-secondary)]">
            <span className="font-medium">{t('projects.task_date_range')}:</span>{' '}
            {t('projects.from')} <span className="font-medium">{projectStartDate}</span> {t('projects.to')} <span className="font-medium">{projectEndDate}</span>
          </p>
        </div>
      ) : (
        <div className={`${compact ? 'mb-3 p-2' : 'mb-4 p-3'} flex items-center gap-2 border border-[var(--border-default)] bg-[var(--surface-base)] rounded-lg`}>
          <span className="h-2 w-2 shrink-0 rounded-full bg-[var(--status-warning)]" />
          <p className="text-xs text-[var(--text-secondary)]">
            {t('projects.set_dates_first_tasks')}
          </p>
        </div>
      )}
      <div className={compact ? 'grid grid-cols-1 gap-2' : 'grid grid-cols-1 gap-4'}>
        {tasks.map((task, index) => {
          const dateRange = getMilestoneDateRange(task.milestone_index);

          return (
            <div key={index} className={`${compact ? 'p-3' : 'p-4'} border border-[var(--border-default)] rounded-lg bg-[var(--surface-muted)]/50`}>
              <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                  <span className={`${compact ? 'h-5 w-5' : 'h-6 w-6'} rounded-full bg-[var(--accent-default)] text-[var(--text-inverse)] flex items-center justify-center text-xs font-medium`}>
                    {index + 1}
                  </span>
                </div>
                {tasks.length > 1 && (
                  <IconButton
                    type="button"
                    variant="dangerStrong"
                  size="xs"
                  onClick={() => onRemoveTask(index)}
                  aria-label={t('common.delete')}
                  className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                >
                    <IconTrash className="h-3.5 w-3.5" />
                  </IconButton>
                )}
              </div>
              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.task_name')}</label>
                  <Input
                    value={task.name}
                    onChange={(e) => onTaskChange(index, 'name', e.target.value)}
                    placeholder={t('projects.task_name_placeholder')}
                    className="min-h-11 text-sm lg:min-h-9"
                  />
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.milestone_optional')}</label>
                    <Select
                      value={task.milestone_index?.toString() || ''}
                      onChange={(e) => onTaskChange(index, 'milestone_index', e.target.value ? parseInt(e.target.value) : undefined)}
                      options={milestoneOptions}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.priority')}</label>
                    <Select
                      value={task.priority}
                      onChange={(e) => onTaskChange(index, 'priority', e.target.value)}
                      options={translatedTaskPriorityOptions}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.assignee_optional')}</label>
                  <Select
                    options={userOptions}
                    value={task.assigned_to ? String(task.assigned_to) : ''}
                    onChange={(e) => onSelectAssignee(index, Number(e.target.value))}
                    placeholder={t('projects.select_assignee')}
                    searchable
                  />
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.start_date')}</label>
                    <DatePicker
                      value={task.start_date}
                      onChange={(value) => onTaskChange(index, 'start_date', value)}
                      minDate={dateRange.minDate || undefined}
                      maxDate={task.due_date || dateRange.maxDate || undefined}
                      placeholder={t('projects.start_date')}
                      disabled={!hasDateRange}
                      className="min-h-11 lg:min-h-9"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.end_date')}</label>
                    <DatePicker
                      value={task.due_date}
                      onChange={(value) => onTaskChange(index, 'due_date', value)}
                      minDate={task.start_date || dateRange.minDate || undefined}
                      maxDate={dateRange.maxDate || undefined}
                      placeholder={t('projects.end_date')}
                      disabled={!hasDateRange}
                      className="min-h-11 lg:min-h-9"
                    />
                  </div>
                </div>

                <DurationChips
                  startDate={task.start_date}
                  endDate={task.due_date}
                  onApply={(start, end) => {
                    onTaskChange(index, 'start_date', start);
                    onTaskChange(index, 'due_date', end);
                  }}
                  options={TASK_DURATIONS}
                  fallbackStart={dateRange.minDate || undefined}
                  disabled={!hasDateRange}
                />

                <div>
                  <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.description_optional')}</label>
                  <Input
                    value={task.description}
                    onChange={(e) => onTaskChange(index, 'description', e.target.value)}
                    placeholder={t('projects.task_description_placeholder')}
                    className="min-h-11 text-sm lg:min-h-9"
                  />
                </div>
              </div>
            </div>
          );
        })}
      </div>
      <div className={compact ? 'mt-3' : 'mt-4'}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onAddTask}
          leftIcon={<IconPlus className="h-4 w-4" />}
          disabled={!hasDateRange}
          className="h-11 lg:h-8"
        >
          {t('projects.add_task')}
        </Button>
      </div>
    </>
  );

  if (compact) {
    return <div>{content}</div>;
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconClipboardList className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.tasks')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {content}
      </CardContent>
    </Card>
  );
});

InlineTasksEditor.displayName = 'InlineTasksEditor';

export default InlineTasksEditor;
