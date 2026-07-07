import { memo, useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import {IconFlag, IconCalendar, IconClock} from '@tabler/icons-react';
import { formatDate } from '@shared/lib/utils';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { DatePicker } from '@shared/ui/DatePicker';
import { statusOptions, priorityOptions } from './constants';
import type { TaskFormData, DateConstraints, ValidationErrors } from './types';

// خيارات عدد الأيام للتسليم
const DAYS_OPTIONS = [
  { value: 1, labelKey: 'tasks.days_1' },
  { value: 2, labelKey: 'tasks.days_2' },
  { value: 3, labelKey: 'tasks.days_3' },
  { value: 5, labelKey: 'tasks.days_5' },
  { value: 7, labelKey: 'tasks.days_7' },
  { value: 14, labelKey: 'tasks.days_14' },
  { value: 30, labelKey: 'tasks.days_30' },
];

interface StatusScheduleSectionProps {
  formData: TaskFormData;
  errors: ValidationErrors;
  isEditMode: boolean;
  dateConstraints: DateConstraints | null;
  onChange: (field: keyof TaskFormData, value: string) => void;
}

const StatusScheduleSection = memo<StatusScheduleSectionProps>(({
  formData,
  errors,
  isEditMode,
  dateConstraints,
  onChange,
}) => {
  const { t } = useTranslation();
  // حالة لتتبع الأيام المختارة
  const [selectedDays, setSelectedDays] = useState<number | null>(null);
  // وضع اختيار تاريخ التسليم: 'days' أو 'calendar'
  const [dueDateMode, setDueDateMode] = useState<'days' | 'calendar'>('days');

  // حساب تاريخ التسليم بناءً على عدد الأيام
  const calculateDueDate = useCallback((days: number) => {
    const startDate = formData.start_date ? new Date(formData.start_date) : new Date();
    const dueDate = new Date(startDate);
    dueDate.setDate(dueDate.getDate() + days);
    return dueDate.toISOString().split('T')[0];
  }, [formData.start_date]);

  // اختيار عدد الأيام
  const handleDaysSelect = (days: number) => {
    setSelectedDays(days);
    const newDueDate = calculateDueDate(days);
    onChange('due_date', newDueDate);
  };

  // تنسيق التاريخ للعرض
  const formatDisplayDate = (dateStr: string) => {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ar-EG-u-nu-latn', {
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  return (
    <>
      <div className="flex items-center gap-2 mb-4">
        <IconFlag className="h-4 w-4 text-[var(--accent-default)]" />
        <h3 className="text-sm font-semibold text-[var(--text-primary)]">{t('tasks.status_schedule')}</h3>
      </div>

      {dateConstraints && (
        <div className="mb-4 p-3 bg-[var(--accent-subtle)] border border-[var(--accent-default)] rounded-lg">
          <p className="text-xs text-[var(--accent-default)]">
            <span className="font-medium">{t('tasks.date_range_allowed')}:</span>{' '}
            {dateConstraints.constraintType === 'milestone' ? t('tasks.milestone') : t('tasks.project')} "{dateConstraints.constraintName}" -
            {t('tasks.from')} <span className="font-medium">{formatDate(dateConstraints.minDate)}</span> {t('common.to')} <span className="font-medium">{formatDate(dateConstraints.maxDate)}</span>
          </p>
        </div>
      )}

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        {/* الحالة - تظهر فقط في وضع التعديل */}
        {isEditMode && (
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('common.status')}</label>
            <Select
              value={formData.status}
              onChange={(e) => onChange('status', e.target.value)}
              options={statusOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }))}
            />
          </div>
        )}
        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('common.priority')}</label>
          <Select
            value={formData.priority}
            onChange={(e) => onChange('priority', e.target.value)}
            options={priorityOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }))}
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('common.start_date')}</label>
          <DatePicker
            value={formData.start_date}
            onChange={(value) => {
              onChange('start_date', value);
              // إعادة حساب تاريخ التسليم إذا كان هناك أيام مختارة
              if (selectedDays) {
                const startDate = value ? new Date(value) : new Date();
                const dueDate = new Date(startDate);
                dueDate.setDate(dueDate.getDate() + selectedDays);
                onChange('due_date', dueDate.toISOString().split('T')[0]);
              }
            }}
            minDate={dateConstraints?.minDate}
            maxDate={dateConstraints?.maxDate}
            placeholder={t('tasks.select_start_date')}
            error={errors.start_date?.[0]}
          />
        </div>

        {/* تاريخ التسليم مع خيارات عدد الأيام */}
        <div className="col-span-2 md:col-span-1 lg:col-span-1">
          <div className="flex items-center justify-between mb-1">
            <label className="block text-xs font-medium text-[var(--text-secondary)]">{t('tasks.delivery_date')}</label>
            <div className="flex gap-1">
              <button
                type="button"
                onClick={() => setDueDateMode('days')}
                className={`p-1 rounded text-xs ${dueDateMode === 'days' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('tasks.select_by_days')}
                aria-label={t('tasks.select_by_days')}
              >
                <IconClock className="h-3.5 w-3.5" />
              </button>
              <button
                type="button"
                onClick={() => setDueDateMode('calendar')}
                className={`p-1 rounded text-xs ${dueDateMode === 'calendar' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('tasks.select_from_calendar')}
                aria-label={t('tasks.select_from_calendar')}
              >
                <IconCalendar className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          {dueDateMode === 'days' ? (
            <div className="space-y-2">
              <div className="flex flex-wrap gap-1">
                {DAYS_OPTIONS.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => handleDaysSelect(option.value)}
                    className={`
                      px-2 py-1 text-xs rounded-lg border transition-colors
                      focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]
                      ${selectedDays === option.value
                        ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)] font-medium'
                        : 'border-[var(--border-default)] hover:border-[var(--border-strong)] text-[var(--text-secondary)] hover:bg-[var(--surface-subtle)]'
                      }
                    `}
                  >
                    {t(option.labelKey)}
                  </button>
                ))}
              </div>
              {formData.due_date && (
                <p className="text-xs text-[var(--text-secondary)]">
                  {t('tasks.delivery')}: <span className="font-medium text-[var(--text-primary)]">{formatDisplayDate(formData.due_date)}</span>
                </p>
              )}
            </div>
          ) : (
            <DatePicker
              value={formData.due_date}
              onChange={(value) => {
                onChange('due_date', value);
                setSelectedDays(null); // مسح الأيام المختارة عند الاختيار اليدوي
              }}
              minDate={formData.start_date || dateConstraints?.minDate}
              maxDate={dateConstraints?.maxDate}
              placeholder={t('tasks.select_due_date')}
              error={errors.due_date?.[0]}
            />
          )}
          {errors.due_date && dueDateMode === 'days' && (
            <p className="text-xs text-[var(--status-danger)] mt-1">{errors.due_date[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.estimated_hours')}</label>
          <Input
            type="number"
            value={formData.estimated_hours}
            onChange={(e) => onChange('estimated_hours', e.target.value)}
            placeholder="0"
            min="0"
            step="0.5"
          />
        </div>
      </div>
    </>
  );
});

StatusScheduleSection.displayName = 'StatusScheduleSection';

export default StatusScheduleSection;
