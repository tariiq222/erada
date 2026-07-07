import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Textarea } from '@shared/ui/Textarea';
import { SectionHeader } from '@shared/ui/SectionHeader';
import {IconSearch, IconUsers, IconScanEye, IconBulb, IconGitBranch, IconTrendingUp, type LucideIcon} from '@tabler/icons-react';
import type { KpiInput, ProjectFormData, UserOption } from './types';
import TeamSection from './TeamSection';
import KpiRepeater from './KpiRepeater';

// Improvement projects don't use PMBOK scope/risks fields.

interface ImprovementFormStepsProps {
  formData: ProjectFormData;
  errors: Record<string, string[]>;
  handleChange: (field: keyof ProjectFormData, value: ProjectFormData[keyof ProjectFormData]) => void;
  users: UserOption[];
  addTeamMember: () => void;
  removeTeamMember: (index: number) => void;
  updateTeamMember: (index: number, userId: number, userName: string) => void;
  onKpiChange: (index: number, field: keyof KpiInput, value: string) => void;
  onAddKpi: () => void;
  onRemoveKpi: (index: number) => void;
}

const ImprovementFormSteps = memo<ImprovementFormStepsProps>(({
  formData,
  errors,
  handleChange,
  users,
  addTeamMember,
  removeTeamMember,
  updateTeamMember,
  onKpiChange,
  onAddKpi,
  onRemoveKpi,
}) => {
  const { t } = useTranslation();
  const pdcaPhases = [
    { value: 'P', labelKey: 'projects.pdca_plan' },
    { value: 'D', labelKey: 'projects.pdca_do' },
    { value: 'C', labelKey: 'projects.pdca_check' },
    { value: 'A', labelKey: 'projects.pdca_act' },
  ];
  const compactSectionClassName = 'space-y-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-subtle)] p-3 sm:p-4';
  const compactTextareaClassName = 'min-h-[84px] resize-none';

  const handleSelectTeamMember = (index: number, userId: number) => {
    const user = users.find((u) => u.id === userId);
    updateTeamMember(index, userId, user?.name ?? '');
  };

  const renderCompactSectionHeading = (Icon: LucideIcon, title: string) => (
    <SectionHeader
      title={title}
      icon={Icon}
      iconTone="project"
      size="compact"
      className="border-b border-[var(--border-default)] pb-2"
    />
  );

  // The improvement form is always rendered as a single page (the legacy
  // stepper branches were removed). This layout is the only one in use.
  return (
      <div className="space-y-5">
        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconSearch, t('projects.focus_find_section'))}
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <Textarea
              label={t('projects.target_process')}
              value={formData.target_process}
              onChange={(e) => handleChange('target_process', e.target.value)}
              placeholder={t('projects.target_process_placeholder')}
              rows={2}
              error={errors.target_process?.[0]}
              className={compactTextareaClassName}
            />
            <Textarea
              label={t('projects.problem_statement')}
              value={formData.problem_statement}
              onChange={(e) => handleChange('problem_statement', e.target.value)}
              placeholder={t('projects.problem_statement_placeholder')}
              rows={2}
              error={errors.problem_statement?.[0]}
              className={compactTextareaClassName}
            />
          </div>
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconUsers, t('projects.focus_organize_section'))}
          <TeamSection
            teamMembers={formData.team_members}
            users={users}
            onAddTeamMember={addTeamMember}
            onRemoveTeamMember={removeTeamMember}
            onSelectTeamMember={handleSelectTeamMember}
            compact
          />
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconScanEye, t('projects.focus_clarify_section'))}
          {/* The structured KpiRepeater below is the single source for KPIs; the
              former free-text "KPI" textarea (bound to success_criteria) was
              removed to avoid the success_criteria double-duty. */}
          <Textarea
            label={t('projects.current_state')}
            value={formData.description}
            onChange={(e) => handleChange('description', e.target.value)}
            placeholder={t('projects.current_state_placeholder')}
            rows={2}
            error={errors.description?.[0]}
            className={compactTextareaClassName}
          />
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconBulb, t('projects.focus_understand_section'))}
          <Textarea
            label={t('projects.root_cause')}
            value={formData.root_cause}
            onChange={(e) => handleChange('root_cause', e.target.value)}
            placeholder={t('projects.root_cause_placeholder')}
            rows={2}
            error={errors.root_cause?.[0]}
            className={compactTextareaClassName}
          />
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconBulb, t('projects.focus_select_section'))}
          <Textarea
            label={t('projects.expected_benefits')}
            value={formData.expected_benefits}
            onChange={(e) => handleChange('expected_benefits', e.target.value)}
            placeholder={t('projects.expected_benefits_placeholder')}
            rows={2}
            error={errors.expected_benefits?.[0]}
            className={compactTextareaClassName}
          />
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconTrendingUp, t('projects.kpis_title'))}
          <KpiRepeater
            kpis={formData.kpis}
            errors={errors}
            onKpiChange={onKpiChange}
            onAddKpi={onAddKpi}
            onRemoveKpi={onRemoveKpi}
            compact
          />
        </section>

        <section className={compactSectionClassName}>
          {renderCompactSectionHeading(IconGitBranch, t('projects.pdca_phases'))}
          <p className="text-sm text-[var(--text-secondary)]">
            {t('projects.select_pdca_phase')}
          </p>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-4">
            {pdcaPhases.map((phase) => {
              const isSelected = formData.current_pdca_phase === phase.value;
              return (
                <button
                  key={phase.value}
                  type="button"
                  onClick={() => handleChange('current_pdca_phase', phase.value)}
                  className={`
                    min-h-16 rounded-lg border p-3 text-center transition-colors
                    focus-visible:outline-none focus-visible:border-[var(--accent-default)] focus-visible:shadow-[0_0_0_2px_var(--accent-subtle)]
                    ${isSelected
                      ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                      : 'border-[var(--border-default)] bg-[var(--surface-base)] text-[var(--text-secondary)] hover:border-[var(--accent-default)]'
                    }
                  `}
                >
                  <div className="text-lg font-bold leading-none">{phase.value}</div>
                  <div className="mt-1 text-xs font-medium">{t(phase.labelKey)}</div>
                </button>
              );
            })}
          </div>
        </section>
      </div>
  );
});

ImprovementFormSteps.displayName = 'ImprovementFormSteps';

export default ImprovementFormSteps;
