import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconLayoutKanban, IconUser, IconPlus} from '@tabler/icons-react';
import { Button } from '@shared/ui/Button';
import { Select } from '@shared/ui/Select';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import type { ProjectOption, MilestoneOption, TaskOption, UserOption, TaskFormData, ValidationErrors } from './types';

interface ProjectMilestoneSectionProps {
  formData: TaskFormData;
  projects: ProjectOption[];
  milestones: MilestoneOption[];
  parentTasks: TaskOption[];
  selectedUser: UserOption | undefined;
  errors: ValidationErrors;
  onChange: (field: keyof TaskFormData, value: string) => void;
  onOpenMilestoneModal: () => void;
  onOpenUserModal: () => void;
}

const ProjectMilestoneSection = memo<ProjectMilestoneSectionProps>(({
  formData,
  projects,
  milestones,
  parentTasks,
  selectedUser,
  errors,
  onChange,
  onOpenMilestoneModal,
  onOpenUserModal,
}) => {
  const { t } = useTranslation();
  return (
    <>
      <div className="flex items-center gap-2 mb-4">
        <IconLayoutKanban className="h-4 w-4 text-[var(--accent-default)]" />
        <h3 className="text-sm font-semibold text-[var(--text-primary)]">{t('tasks.project_milestone')}</h3>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">
            {t('tasks.project')} <RequiredIndicator />
          </label>
          <Select
            value={formData.project_id}
            onChange={(e) => onChange('project_id', e.target.value)}
            options={[
              { value: '', label: t('tasks.select_project') },
              ...projects.map((p) => ({
                value: p.id.toString(),
                label: `${p.code} - ${p.name}`
              })),
            ]}
          />
          {errors.project_id && (
            <p className="text-xs text-[var(--status-danger)] mt-1">{errors.project_id[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.milestone')}</label>
          <div className="flex gap-1">
            <div className="flex-1">
              <Select
                value={formData.milestone_id}
                onChange={(e) => onChange('milestone_id', e.target.value)}
                options={[
                  { value: '', label: t('tasks.select_milestone') },
                  ...milestones.map((m) => ({
                    value: m.id.toString(),
                    label: m.name
                  })),
                ]}
                disabled={!formData.project_id}
              />
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onOpenMilestoneModal}
              disabled={!formData.project_id}
              className="shrink-0 h-[42px] px-2"
            >
              <IconPlus className="h-4 w-4" />
            </Button>
          </div>
        </div>

        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.parent_task')}</label>
          <Select
            value={formData.parent_id}
            onChange={(e) => onChange('parent_id', e.target.value)}
            options={[
              { value: '', label: t('tasks.none') },
              ...parentTasks.map((t) => ({
                value: t.id.toString(),
                label: t.title
              })),
            ]}
            disabled={!formData.project_id}
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.assignee')}</label>
          <button
            type="button"
            onClick={onOpenUserModal}
            className="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm border border-[var(--border-default)] rounded-lg bg-[var(--surface-base)] hover:border-[var(--accent-default)] hover:bg-[var(--surface-subtle)] transition-colors text-start"
          >
            <div className="flex items-center gap-2 min-w-0">
              <div className={`h-7 w-7 rounded-full flex items-center justify-center shrink-0 ${selectedUser ? 'bg-[var(--accent-subtle)]' : 'bg-[var(--surface-muted)]'}`}>
                <IconUser className={`h-4 w-4 ${selectedUser ? 'text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'}`} />
              </div>
              <span className={`truncate ${selectedUser ? 'text-[var(--text-primary)]' : 'text-[var(--text-tertiary)]'}`}>
                {selectedUser?.name || t('tasks.select_assignee')}
              </span>
            </div>
            <IconPlus className="h-4 w-4 text-[var(--text-tertiary)] shrink-0" />
          </button>
        </div>
      </div>
    </>
  );
});

ProjectMilestoneSection.displayName = 'ProjectMilestoneSection';

export default ProjectMilestoneSection;
