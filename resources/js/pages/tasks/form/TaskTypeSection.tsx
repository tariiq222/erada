import React, { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconStack2, IconUser, IconBuilding, IconRefresh} from '@tabler/icons-react';
import { Select } from '@shared/ui/Select';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import type { TaskFormData, ValidationErrors, DepartmentOption, TaskType } from './types';

interface TaskTypeSectionProps {
  formData: TaskFormData;
  departments: DepartmentOption[];
  errors: ValidationErrors;
  onChange: (field: keyof TaskFormData, value: string | boolean) => void;
}

const TASK_TYPES: { value: TaskType; labelKey: string; icon: React.ComponentType<{ className?: string }>; descriptionKey: string }[] = [
  { value: 'project', labelKey: 'task_type.project', icon: IconStack2, descriptionKey: 'tasks.type_desc_project' },
  { value: 'personal', labelKey: 'task_type.personal', icon: IconUser, descriptionKey: 'tasks.type_desc_personal' },
  { value: 'department', labelKey: 'task_type.department', icon: IconBuilding, descriptionKey: 'tasks.type_desc_department' },
  { value: 'recurring', labelKey: 'task_type.recurring', icon: IconRefresh, descriptionKey: 'tasks.type_desc_recurring' },
];

const RECURRENCE_OPTIONS = [
  { value: '', labelKey: 'tasks.select_recurrence' },
  { value: 'daily', labelKey: 'tasks.recurrence_daily' },
  { value: 'weekly', labelKey: 'tasks.recurrence_weekly' },
  { value: 'biweekly', labelKey: 'tasks.recurrence_biweekly' },
  { value: 'monthly', labelKey: 'tasks.recurrence_monthly' },
  { value: 'quarterly', labelKey: 'tasks.recurrence_quarterly' },
  { value: 'yearly', labelKey: 'tasks.recurrence_yearly' },
];

const TaskTypeSection = memo<TaskTypeSectionProps>(({
  formData,
  departments,
  errors,
  onChange,
}) => {
  const { t } = useTranslation();
  return (
    <>
      <div className="flex items-center gap-2 mb-4">
        <IconStack2 className="h-4 w-4 text-[var(--accent-default)]" />
        <h3 className="text-sm font-semibold text-[var(--text-primary)]">{t('tasks.task_type')}</h3>
      </div>

      {/* Task Type Selection */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        {TASK_TYPES.map(({ value, labelKey, icon: Icon, descriptionKey }) => (
          <button
            key={value}
            type="button"
            onClick={() => onChange('type', value)}
            className={`
              flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-colors text-center
              focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]
              ${formData.type === value
                ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                : 'border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)] text-[var(--text-secondary)]'
              }
            `}
          >
            <Icon className={`h-6 w-6 ${formData.type === value ? 'text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'}`} />
            <span className="font-medium text-sm">{t(labelKey)}</span>
            <span className="text-xs text-[var(--text-secondary)]">{t(descriptionKey)}</span>
          </button>
        ))}
      </div>

      {/* Type-specific fields */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {/* Department field - for department tasks */}
        {formData.type === 'department' && (
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">
              {t('common.department')} <RequiredIndicator />
            </label>
            <Select
              value={formData.department_id}
              onChange={(e) => onChange('department_id', e.target.value)}
              options={[
                { value: '', label: t('tasks.select_department') },
                ...departments.map((d) => ({
                  value: d.id.toString(),
                  label: d.name,
                })),
              ]}
            />
            {errors.department_id && (
              <p className="text-xs text-[var(--status-danger)] mt-1">{errors.department_id[0]}</p>
            )}
          </div>
        )}

        {/* Recurrence field - for recurring tasks */}
        {formData.type === 'recurring' && (
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">
              {t('tasks.recurrence_pattern')} <RequiredIndicator />
            </label>
            <Select
              value={formData.recurrence_rule}
              onChange={(e) => onChange('recurrence_rule', e.target.value)}
              options={RECURRENCE_OPTIONS.map(opt => ({ value: opt.value, label: t(opt.labelKey) }))}
            />
            {errors.recurrence_rule && (
              <p className="text-xs text-[var(--status-danger)] mt-1">{errors.recurrence_rule[0]}</p>
            )}
          </div>
        )}

      </div>
    </>
  );
});

TaskTypeSection.displayName = 'TaskTypeSection';

export default TaskTypeSection;
