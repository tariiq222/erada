import React from 'react';
import {IconLayoutKanban, IconUser, IconFlag, IconFileText, IconPlus} from '@tabler/icons-react';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { DatePicker } from '@shared/ui/DatePicker';
import { formatDate } from '@shared/lib/utils';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import {
  TaskFormData,
  MilestoneOption,
  TaskOption,
  UserOption,
  RecommendationOption,
  ValidationErrors,
  priorityOptions,
} from './types';

interface DateConstraints {
  minDate: string;
  maxDate: string;
  constraintType: 'milestone' | 'project';
  constraintName: string;
}

interface TaskFormFieldsProps {
  formData: TaskFormData;
  onChange: (field: keyof TaskFormData, value: string) => void;
  errors: ValidationErrors;
  milestones: MilestoneOption[];
  parentTasks: TaskOption[];
  users: UserOption[];
  dateConstraints: DateConstraints | null;
  onOpenUserModal: () => void;
  // Direction B — optional recommendation picker (only rendered when set).
  recommendations?: RecommendationOption[];
}

const TaskFormFields: React.FC<TaskFormFieldsProps> = ({
  formData,
  onChange,
  errors,
  milestones,
  parentTasks,
  users,
  dateConstraints,
  onOpenUserModal,
  recommendations,
}) => {
  const selectedUser = users?.find(u => u.id === Number(formData.assigned_to));

  return (
    <div className="space-y-5">
      {/* Section: المرحلة والمهمة الأم */}
      <div>
        <div className="flex items-center gap-2 mb-3">
          <IconLayoutKanban className="h-4 w-4 text-[var(--accent-default)]" />
          <h3 className="text-sm font-semibold text-[var(--text-secondary)]">المرحلة والتبعية</h3>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">المسؤول</label>
            <button
              type="button"
              onClick={onOpenUserModal}
              className="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm border border-[var(--border-strong)] rounded-lg bg-white hover:border-[var(--accent-default)] hover:bg-[var(--surface-subtle)] transition-colors text-right"
            >
              <div className="flex items-center gap-2 min-w-0">
                <div className={`h-5 w-5 rounded-full flex items-center justify-center shrink-0 ${selectedUser ? 'bg-[var(--accent-subtle)]' : 'bg-[var(--surface-muted)]'}`}>
                  <IconUser className={`h-3.5 w-3.5 ${selectedUser ? 'text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'}`} />
                </div>
                <span className={`truncate ${selectedUser ? 'text-[var(--text-primary)]' : 'text-[var(--text-tertiary)]'}`}>
                  {selectedUser?.name || '-- اختر المسؤول --'}
                </span>
              </div>
              <IconPlus className="h-4 w-4 text-[var(--text-tertiary)] shrink-0" />
            </button>
          </div>

          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">المهمة الأم</label>
            <Select
              value={formData.parent_id}
              onChange={(e) => onChange('parent_id', e.target.value)}
              options={[
                { value: '', label: '-- بدون --' },
                ...parentTasks.map((t) => ({
                  value: t.id.toString(),
                  label: t.title
                })),
              ]}
            />
          </div>

          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">المرحلة</label>
            <Select
              value={formData.milestone_id}
              onChange={(e) => onChange('milestone_id', e.target.value)}
              options={[
                { value: '', label: '-- اختر المرحلة --' },
                ...milestones.map((m) => ({
                  value: m.id.toString(),
                  label: m.name
                })),
              ]}
            />
          </div>
        </div>
      </div>

      {/* Divider */}
      <div className="border-t border-[var(--border-default)]" />

      {/* Section: تفاصيل المهمة */}
      <div>
        <div className="flex items-center gap-2 mb-3">
          <IconFileText className="h-4 w-4 text-[var(--accent-default)]" />
          <h3 className="text-sm font-semibold text-[var(--text-secondary)]">تفاصيل المهمة</h3>
        </div>
        <div className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">
              عنوان المهمة <RequiredIndicator />
            </label>
            <Input
              value={formData.title}
              onChange={(e) => onChange('title', e.target.value)}
              placeholder="أدخل عنوان المهمة"
              error={errors.title?.[0]}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">وصف المهمة</label>
            <textarea
              value={formData.description}
              onChange={(e) => onChange('description', e.target.value)}
              placeholder="وصف مختصر للمهمة"
              rows={2}
              className="w-full px-3 py-2 text-sm border border-[var(--border-strong)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)]/20 focus:border-[var(--accent-default)] resize-none"
            />
          </div>
        </div>
      </div>

      {/* Divider */}
      <div className="border-t border-[var(--border-default)]" />

      {/* Section: المصدر (Direction B) */}
      {recommendations && recommendations.length > 0 && (
        <div>
          <div className="flex items-center gap-2 mb-3">
            <IconFileText className="h-4 w-4 text-[var(--accent-default)]" />
            <h3 className="text-sm font-semibold text-[var(--text-secondary)]">
              مصدر المهمة
            </h3>
          </div>
          <div className="grid grid-cols-1 gap-3">
            <Select
              value={formData.source_type}
              onChange={(e) => {
                onChange('source_type', e.target.value);
                if (!e.target.value) onChange('source_id', '');
              }}
              options={[
                { value: '', label: '-- بدون مصدر --' },
                { value: 'recommendation', label: 'قرار/إجراء من اجتماع' },
              ]}
            />
            {formData.source_type === 'recommendation' && (
              <Select
                value={formData.source_id}
                onChange={(e) => onChange('source_id', e.target.value)}
                options={[
                  { value: '', label: '-- اختر القرار/الإجراء --' },
                  ...recommendations.map((r) => ({
                    value: r.id.toString(),
                    label: `${r.reference_number ?? '#' + r.id} — ${r.title}`,
                  })),
                ]}
              />
            )}
          </div>
        </div>
      )}

      {/* Divider */}
      <div className="border-t border-[var(--border-default)]" />

      {/* Section: الجدول الزمني */}
      <div>
        <div className="flex items-center gap-2 mb-3">
          <IconFlag className="h-4 w-4 text-[var(--accent-default)]" />
          <h3 className="text-sm font-semibold text-[var(--text-secondary)]">الأولوية والجدول الزمني</h3>
        </div>

        {dateConstraints && (
          <div className="mb-3 p-2 bg-[var(--accent-subtle)] border border-[var(--accent-subtle)] rounded-lg">
            <p className="text-xs text-[var(--accent-default)]">
              <span className="font-medium">نطاق التواريخ:</span>{' '}
              {dateConstraints.constraintType === 'milestone' ? 'المرحلة' : 'المشروع'} "{dateConstraints.constraintName}" -
              من <span className="font-medium">{formatDate(dateConstraints.minDate)}</span> إلى <span className="font-medium">{formatDate(dateConstraints.maxDate)}</span>
            </p>
          </div>
        )}

        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">الأولوية</label>
            <Select
              value={formData.priority}
              onChange={(e) => onChange('priority', e.target.value)}
              options={priorityOptions}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">تاريخ البداية</label>
            <DatePicker
              value={formData.start_date}
              onChange={(value) => onChange('start_date', value)}
              minDate={dateConstraints?.minDate}
              maxDate={dateConstraints?.maxDate}
              placeholder="اختر التاريخ"
              error={errors.start_date?.[0]}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">تاريخ التسليم</label>
            <DatePicker
              value={formData.due_date}
              onChange={(value) => onChange('due_date', value)}
              minDate={formData.start_date || dateConstraints?.minDate}
              maxDate={dateConstraints?.maxDate}
              placeholder="اختر التاريخ"
              error={errors.due_date?.[0]}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">الساعات المتوقعة</label>
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
      </div>
    </div>
  );
};

export default TaskFormFields;
