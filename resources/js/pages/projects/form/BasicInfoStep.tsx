import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconLayoutKanban, IconBuilding, IconCalendar, IconCurrencyDollar, IconLink} from '@tabler/icons-react';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { Textarea } from '@shared/ui/Textarea';
import { Select } from '@shared/ui/Select';
import { Checkbox } from '@shared/ui/Checkbox';
import { DatePicker } from '@shared/ui/DatePicker';
import { DurationChips, PROJECT_DURATIONS } from '@shared/ui/DurationChips';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { FieldHelp } from '@shared/ui/FieldHelp';
import { statusOptions, priorityOptions } from './constants';
import type {
  ProjectFormData,
  DepartmentOption,
  ProgramOption,
  ValidationErrors,
} from './types';

export interface AssignableManagerOption {
  id: number;
  name: string;
  email: string;
  job_title: string | null;
  department_id: number | null;
}

interface BasicInfoStepProps {
  formData: ProjectFormData;
  allDepartments: DepartmentOption[];
  programs: ProgramOption[];
  errors: ValidationErrors;
  onChangeField: (field: keyof ProjectFormData, value: ProjectFormData[keyof ProjectFormData]) => void;
  hideDescription?: boolean;
  compact?: boolean;
  /** When true, lay the compact section out as 2 columns instead of 5 (for use inside a half-width column). */
  narrow?: boolean;
  /** When true (compact only), render just the fields grid without the section wrapper/header, so a parent SectionHeader can own the chrome. */
  bare?: boolean;
  // Assignable-manager wiring. All optional so existing callers (which
  // don't yet know about the assignable-manager flow) keep working.
  isSelfManager?: boolean;
  setIsSelfManager?: (value: boolean) => void;
  assignedManagerId?: string;
  setAssignedManagerId?: (value: string) => void;
  assignableManagers?: AssignableManagerOption[];
  isLoadingAssignableManagers?: boolean;
  /**
   * When true (edit form), the `completed` status option is hidden. Completion
   * must go through the dedicated ClosureModal flow on ProjectView, which
   * collects the closure fields the backend requires (a plain status->completed
   * edit hits a 422 demanding fields the edit form has no inputs for).
   */
  isEditMode?: boolean;
}

const BasicInfoStep: React.FC<BasicInfoStepProps> = ({
  formData,
  allDepartments,
  programs,
  errors,
  onChangeField,
  hideDescription = false,
  compact = false,
  narrow = false,
  bare = false,
  // Assignable-manager wiring with safe defaults so existing callers
  // (which don't yet know about this flow) keep rendering unchanged.
  isSelfManager = true,
  setIsSelfManager,
  assignedManagerId = '',
  setAssignedManagerId,
  assignableManagers = [],
  isLoadingAssignableManagers = false,
  isEditMode = false,
}) => {
  const { t } = useTranslation();

  // Quick-duration: fill start (today if empty) + end in one tap.
  const applyProjectDuration = (start: string, end: string) => {
    onChangeField('start_date', start);
    onChangeField('end_date', end);
  };

  // On edit, drop `completed` so users can't trigger the closure 422 from the
  // status select; completion goes through ProjectView's ClosureModal instead.
  const translatedStatusOptions = statusOptions
    .filter((opt) => !(isEditMode && opt.value === 'completed'))
    .map(opt => ({ value: opt.value, label: t(opt.labelKey) }));
  const translatedPriorityOptions = priorityOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));

  // Assignable-manager picker label and options.
  const assignManagerError = errors.manager_user_id?.[0];
  const assignManagerPlaceholder = isLoadingAssignableManagers
    ? t('projects.loading_managers')
    : assignableManagers.length === 0
      ? t('projects.no_assignable_managers')
      : t('projects.assign_manager_placeholder');
  const assignManagerOptions = [
    ...assignableManagers.map((manager) => ({
      value: manager.id.toString(),
      label: manager.job_title ? `${manager.name} - ${manager.job_title}` : manager.name,
    })),
  ];
  const renderManagerSection = (fullWidth = false) => (
    <div className={fullWidth ? 'sm:col-span-2 xl:col-span-5' : ''}>
      <Checkbox
        id="project-i-am-manager"
        checked={isSelfManager}
        onChange={(e) => setIsSelfManager?.(e.target.checked)}
        label={t('projects.i_am_the_manager')}
        description={t('projects.i_am_the_manager_hint')}
      />
      {!isSelfManager && (
        <div className="mt-2">
          <Select
            id="project-assign-manager"
            value={assignedManagerId}
            onChange={(e) => setAssignedManagerId?.(e.target.value)}
            options={assignManagerOptions}
            placeholder={assignManagerPlaceholder}
            label={t('projects.assign_manager')}
            required
            searchable
            error={assignManagerError}
            disabled={isLoadingAssignableManagers || assignableManagers.length === 0}
          />
        </div>
      )}
    </div>
  );

  if (compact) {
    if (bare) {
      // Restructured layout for the new-project page section-basic card.
      // Rows (all collapse to a single column below 768px via md:):
      //   1. project name — full width
      //   2. department | program | status (3 equal cols)
      //   3. priority | start date | end date (1 : 1.5 : 1.5)
      //   4. budget | description (2 equal cols)
      //   5. self-manager banner — at the END so the name remains first.
      return (
        <div className="space-y-4">
          {/* Required-fields hint. */}
          <p className="text-xs text-[var(--text-secondary)]">
            <span className="text-[var(--status-danger)]">*</span>{' '}
            {t('projects.required_fields_hint')}
          </p>

          {/* Row 1: project name (full width, first). */}
          <div>
            <div className="mb-1 flex items-center gap-1">
              <label htmlFor="project-name-compact" className="block text-sm font-semibold text-[var(--text-primary)]">
                {t('projects.name')} <RequiredIndicator />
              </label>
              <FieldHelp content={t('projects.help.name')} />
            </div>
            <Input
              id="project-name-compact"
              value={formData.name}
              onChange={(e) => onChangeField('name', e.target.value)}
              placeholder={t('projects.enter_project_name')}
              error={errors.name?.[0]}
              className="min-h-11 text-base"
            />
          </div>

          {/* Row 2: department | program | status. */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-department-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  <IconBuilding className="h-4 w-4 inline me-1" />
                  {t('projects.department')}
                </label>
                <FieldHelp content={t('projects.help.department')} />
              </div>
              <Select
                id="project-department-compact"
                value={formData.department_id}
                onChange={(e) => onChangeField('department_id', e.target.value)}
                disabled={allDepartments.length === 0}
                className="min-h-11"
                error={errors.department_id?.[0]}
                options={[
                  {
                    value: '',
                    label:
                      allDepartments.length === 0
                        ? t('projects.no_departments')
                        : t('projects.select_department'),
                  },
                  ...allDepartments.map((dept) => ({
                    value: dept.id.toString(),
                    label: `${dept.name} (${dept.level_name})`,
                  })),
                ]}
              />
            </div>
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-program-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  <IconLink className="h-4 w-4 inline me-1" />
                  {t('projects.program_optional')}
                </label>
                <FieldHelp content={t('projects.help.program')} />
              </div>
              <Select
                id="project-program-compact"
                value={formData.program_id}
                onChange={(e) => onChangeField('program_id', e.target.value)}
                className="min-h-11"
                options={[
                  { value: '', label: t('projects.standalone_project') },
                  ...programs.map((prog) => ({
                    value: prog.id.toString(),
                    label: `${prog.code} - ${prog.name}`,
                  })),
                ]}
              />
            </div>
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-status-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  {t('common.status')}
                </label>
                <FieldHelp content={t('projects.help.status')} />
              </div>
              <Select
                id="project-status-compact"
                value={formData.status}
                onChange={(e) => onChangeField('status', e.target.value)}
                options={translatedStatusOptions}
                className="min-h-11"
              />
            </div>
          </div>

          {/* Row 3: priority | start date | end date | budget — single row, 4 equal cols. */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-priority-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  {t('projects.priority')} <RequiredIndicator />
                </label>
                <FieldHelp content={t('projects.help.priority')} />
              </div>
              <Select
                id="project-priority-compact"
                value={formData.priority}
                onChange={(e) => onChangeField('priority', e.target.value)}
                options={translatedPriorityOptions}
                className="min-h-11"
                error={errors.priority?.[0]}
              />
            </div>
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-start-date-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  <IconCalendar className="h-4 w-4 inline me-1" />
                  {t('projects.start_date')}
                </label>
                <FieldHelp content={t('projects.help.start_date')} />
              </div>
              <DatePicker
                id="project-start-date-compact"
                value={formData.start_date}
                onChange={(value) => onChangeField('start_date', value)}
                maxDate={formData.end_date || undefined}
                placeholder={t('projects.select_start_date')}
                className="min-h-11"
              />
            </div>
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-end-date-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  <IconCalendar className="h-4 w-4 inline me-1" />
                  {t('projects.end_date')}
                </label>
                <FieldHelp content={t('projects.help.end_date')} />
              </div>
              <DatePicker
                id="project-end-date-compact"
                value={formData.end_date}
                onChange={(value) => onChangeField('end_date', value)}
                minDate={formData.start_date || undefined}
                placeholder={t('projects.select_end_date')}
                className="min-h-11"
              />
            </div>
            <div>
              <div className="mb-1 flex items-center gap-1">
                <label htmlFor="project-budget-compact" className="block text-sm font-medium text-[var(--text-primary)]">
                  <IconCurrencyDollar className="h-4 w-4 inline me-1" />
                  {t('projects.budget_sar')}
                </label>
                <FieldHelp content={t('projects.help.budget')} />
              </div>
              <Input
                id="project-budget-compact"
                type="number"
                value={formData.budget}
                onChange={(e) => onChangeField('budget', e.target.value)}
                placeholder="0.00"
                className="min-h-11"
              />
            </div>
          </div>

          {/* Quick-duration shortcut for the project span. */}
          <DurationChips
            startDate={formData.start_date}
            endDate={formData.end_date}
            onApply={applyProjectDuration}
            options={PROJECT_DURATIONS}
          />

          {/* Row 4: description (full width). */}
          {!hideDescription && (
            <div>
              <Textarea
                id="project-description-compact"
                label={t('projects.description')}
                help={t('projects.help.description')}
                value={formData.description}
                onChange={(e) => onChangeField('description', e.target.value)}
                placeholder={t('projects.enter_description')}
                rows={2}
                className="min-h-[80px] resize-none"
              />
            </div>
          )}

          {/* Self-manager banner at the END (Issue 8). */}
          <div className="rounded-lg border border-[var(--accent-muted)] bg-[var(--accent-subtle)] p-4">
            {renderManagerSection()}
          </div>
        </div>
      );
    }

    const gridClass = narrow
      ? 'grid grid-cols-1 gap-3 sm:grid-cols-2'
      : 'grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5';
    const nameSpan = narrow ? '' : 'md:col-span-2';
    const programSpan = narrow ? '' : 'xl:col-span-2';
    const descSpan = narrow ? '' : 'md:col-span-2 xl:col-span-5';
    const budgetSpan = narrow ? '' : 'md:col-span-2 xl:col-span-1';
    const fields = (
        <div className={gridClass}>
          {/* (bare=true handled above via early return) */}
          {renderManagerSection(true)}

          <div className={nameSpan}>
            <label htmlFor="project-name-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              {t('projects.name')} <RequiredIndicator />
            </label>
            <Input
              id="project-name-compact"
              value={formData.name}
              onChange={(e) => onChangeField('name', e.target.value)}
              placeholder={t('projects.enter_project_name')}
              error={errors.name?.[0]}
              className="min-h-11"
            />
          </div>

          {!hideDescription && (
            <div className={descSpan}>
              <Textarea
                id="project-description-compact"
                label={t('projects.description')}
                value={formData.description}
                onChange={(e) => onChangeField('description', e.target.value)}
                placeholder={t('projects.enter_description')}
                rows={2}
                className="min-h-[80px] resize-none"
              />
            </div>
          )}

          <div>
            <label htmlFor="project-department-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              <IconBuilding className="h-4 w-4 inline me-1" />
              {t('projects.department')}
            </label>
            <Select
              id="project-department-compact"
              value={formData.department_id}
              onChange={(e) => onChangeField('department_id', e.target.value)}
              disabled={allDepartments.length === 0}
              className="min-h-11"
              error={errors.department_id?.[0]}
              options={[
                {
                  value: '',
                  label:
                    allDepartments.length === 0
                      ? t('projects.no_departments')
                      : t('projects.select_department'),
                },
                ...allDepartments.map((dept) => ({
                  value: dept.id.toString(),
                  label: `${dept.name} (${dept.level_name})`,
                })),
              ]}
            />
          </div>

          <div className={programSpan}>
            <label htmlFor="project-program-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              <IconLink className="h-4 w-4 inline me-1" />
              {t('projects.program_optional')}
            </label>
            <Select
              id="project-program-compact"
              value={formData.program_id}
              onChange={(e) => onChangeField('program_id', e.target.value)}
              className="min-h-11"
              options={[
                { value: '', label: t('projects.standalone_project') },
                ...programs.map((prog) => ({
                  value: prog.id.toString(),
                  label: `${prog.code} - ${prog.name}`,
                })),
              ]}
            />
          </div>

          <div>
            <label htmlFor="project-status-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              {t('common.status')}
            </label>
            <Select
              id="project-status-compact"
              value={formData.status}
              onChange={(e) => onChangeField('status', e.target.value)}
              options={translatedStatusOptions}
              className="min-h-11"
            />
          </div>

          <div>
            <label htmlFor="project-priority-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              {t('projects.priority')} <RequiredIndicator />
            </label>
            <Select
              id="project-priority-compact"
              value={formData.priority}
              onChange={(e) => onChangeField('priority', e.target.value)}
              options={translatedPriorityOptions}
              className="min-h-11"
              error={errors.priority?.[0]}
            />
          </div>

          <div>
            <label htmlFor="project-start-date-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              <IconCalendar className="h-4 w-4 inline me-1" />
              {t('projects.start_date')}
            </label>
            <DatePicker
              id="project-start-date-compact"
              value={formData.start_date}
              onChange={(value) => onChangeField('start_date', value)}
              maxDate={formData.end_date || undefined}
              placeholder={t('projects.select_start_date')}
              className="min-h-11"
            />
          </div>

          <div>
            <label htmlFor="project-end-date-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              {t('projects.end_date')}
            </label>
            <DatePicker
              id="project-end-date-compact"
              value={formData.end_date}
              onChange={(value) => onChangeField('end_date', value)}
              minDate={formData.start_date || undefined}
              placeholder={t('projects.select_end_date')}
              className="min-h-11"
            />
          </div>

          <div className={budgetSpan}>
            <label htmlFor="project-budget-compact" className="block text-sm font-medium text-[var(--text-primary)] mb-1">
              <IconCurrencyDollar className="h-4 w-4 inline me-1" />
              {t('projects.budget_sar')}
            </label>
            <Input
              id="project-budget-compact"
              type="number"
              value={formData.budget}
              onChange={(e) => onChangeField('budget', e.target.value)}
              placeholder="0.00"
              className="min-h-11"
            />
          </div>
        </div>
    );

    // (bare=true is handled by the early-return branch above.)
    return (
      <section className="space-y-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-subtle)] p-3 sm:p-4">
        <div className="flex items-center gap-2 border-b border-[var(--border-default)] pb-2">
          <IconLayoutKanban className="h-4 w-4 text-[var(--accent-default)]" />
          <h2 className="text-base font-semibold text-[var(--text-primary)]">
            {t('projects.basic_info')}
          </h2>
        </div>
        {fields}
        <DurationChips
          startDate={formData.start_date}
          endDate={formData.end_date}
          onApply={applyProjectDuration}
          options={PROJECT_DURATIONS}
        />
      </section>
    );
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      {/* Basic Info */}
      <Card className="p-0">
        <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
          <CardTitle className="flex items-center gap-2">
            <IconLayoutKanban className="h-5 w-5 text-[var(--accent-default)]" />
            {t('projects.basic_info')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4 sm:p-6">
          <div className="space-y-4">
            <div className="rounded-md border border-[var(--border-default)] bg-[var(--surface-subtle)] p-3">
              {renderManagerSection()}
            </div>

            <div>
              <label htmlFor="project-name" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                {t('projects.name')} <RequiredIndicator />
              </label>
              <Input
                id="project-name"
                value={formData.name}
                onChange={(e) => onChangeField('name', e.target.value)}
                placeholder={t('projects.enter_project_name')}
                error={errors.name?.[0]}
              />
            </div>

            <div>
              <label htmlFor="project-department" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                <IconBuilding className="h-4 w-4 inline me-1" />
                {t('projects.department')}
              </label>
              <Select
                id="project-department"
                value={formData.department_id}
                onChange={(e) => onChangeField('department_id', e.target.value)}
                disabled={allDepartments.length === 0}
                error={errors.department_id?.[0]}
                options={[
                  {
                    value: '',
                    label:
                      allDepartments.length === 0
                        ? t('projects.no_departments')
                        : t('projects.select_department'),
                  },
                  ...allDepartments.map((dept) => ({
                    value: dept.id.toString(),
                    label: `${dept.name} (${dept.level_name})`,
                  })),
                ]}
              />
            </div>

            <div>
              <label htmlFor="project-program" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                <IconLink className="h-4 w-4 inline me-1" />
                {t('projects.program_optional')}
              </label>
              <Select
                id="project-program"
                value={formData.program_id}
                onChange={(e) => onChangeField('program_id', e.target.value)}
                options={[
                  { value: '', label: t('projects.standalone_project') },
                  ...programs.map((prog) => ({
                    value: prog.id.toString(),
                    label: `${prog.code} - ${prog.name}`,
                  })),
                ]}
              />
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {t('projects.link_to_program_hint')}
              </p>
            </div>

            {!hideDescription && (
              <div>
                <Textarea
                  label={t('projects.description')}
                  value={formData.description}
                  onChange={(e) => onChangeField('description', e.target.value)}
                  placeholder={t('projects.enter_description')}
                  rows={3}
                  className="resize-none"
                />
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Status, Priority, Timeline & Budget */}
      <Card className="p-0">
        <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
          <CardTitle className="flex items-center gap-2">
            <IconCalendar className="h-5 w-5 text-[var(--accent-default)]" />
            {t('projects.settings_and_timeline')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4 sm:p-6">
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label htmlFor="project-status" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('common.status')}
                </label>
                <Select
                  id="project-status"
                  value={formData.status}
                  onChange={(e) => onChangeField('status', e.target.value)}
                  options={translatedStatusOptions}
                />
              </div>
              <div>
                <label htmlFor="project-priority" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('projects.priority')} <RequiredIndicator />
                </label>
                <Select
                  id="project-priority"
                  value={formData.priority}
                  onChange={(e) => onChangeField('priority', e.target.value)}
                  options={translatedPriorityOptions}
                  error={errors.priority?.[0]}
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label htmlFor="project-start-date" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('projects.start_date')}
                </label>
                <DatePicker
                  id="project-start-date"
                  value={formData.start_date}
                  onChange={(value) => onChangeField('start_date', value)}
                  maxDate={formData.end_date || undefined}
                  placeholder={t('projects.select_start_date')}
                />
              </div>
              <div>
                <label htmlFor="project-end-date" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('projects.end_date')}
                </label>
                <DatePicker
                  id="project-end-date"
                  value={formData.end_date}
                  onChange={(value) => onChangeField('end_date', value)}
                  minDate={formData.start_date || undefined}
                  placeholder={t('projects.select_end_date')}
                />
              </div>
            </div>

            <div>
              <label htmlFor="project-budget" className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                <IconCurrencyDollar className="h-4 w-4 inline me-1" />
                {t('projects.budget_sar')}
              </label>
              <Input
                id="project-budget"
                type="number"
                value={formData.budget}
                onChange={(e) => onChangeField('budget', e.target.value)}
                placeholder="0.00"
              />
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default BasicInfoStep;
