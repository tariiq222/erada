import React, { useMemo } from 'react';
import { useParams, Link, useSearchParams, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconArrowRight, IconDeviceFloppy, IconLayoutKanban, IconInfoCircle, IconTarget, IconFlag, IconUsers, IconUserCheck, IconAlertTriangle, IconClipboardList, IconBriefcase, IconCheck, type TablerIcon} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui/Card';
import { Textarea } from '@shared/ui/Textarea';
import { Button } from '@shared/ui/Button';
import { Skeleton } from '@shared/ui/Skeleton';
import { PageHeader } from '@shared/ui/PageHeader';
import { SectionHeader, type SectionHeaderIconTone } from '@shared/ui/SectionHeader';
import { FieldHelp } from '@shared/ui/FieldHelp';
import {
  MilestonesSection,
  InlineTasksEditor,
  RisksSection,
  TeamSection,
  StakeholdersSection,
  ObjectivesSection,
  ScopeSection,
  ResourcesSection,
  BasicInfoStep,
  ImprovementFormSteps,
  useProjectForm,
} from './form';

export const ProjectForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const location = useLocation();
  const projectType = searchParams.get('type') || 'development';
  // Triage answers handed off from the triage modal via navigation state.
  const triageAnswers = (location.state as { triageAnswers?: Record<string, string | null> } | null)?.triageAnswers;

  // Use custom hook for form logic
  const {
    formData,
    allDepartments,
    programs,
    users,
    isLoading,
    isSaving,
    draftSaveStatus,
    draftSavedAt,
    errors,
    isEditMode,
    handleChange,
    handleArrayItemChange,
    addArrayItem,
    removeArrayItem,
    handleMilestoneChange,
    addMilestone,
    removeMilestone,
    suggestMilestones,
    handleDeliverableChange,
    addDeliverable,
    removeDeliverable,
    handleRiskChange,
    addRisk,
    removeRisk,
    handleKpiChange,
    addKpi,
    removeKpi,
    handleTaskChange,
    addTask,
    removeTask,
    updateTaskAssignee,
    addTeamMember,
    removeTeamMember,
    updateTeamMember,
    handleStakeholderChange,
    addStakeholder,
    removeStakeholder,
    updateStakeholder,
    handleSubmit,
    handleSaveDraft,
    isSelfManager,
    setIsSelfManager,
    assignedManagerId,
    setAssignedManagerId,
    assignableManagers,
    isLoadingAssignableManagers,
  } = useProjectForm({ id, projectType, triageAnswers });

  // On edit, derive the layout from the loaded project type (the URL may not
  // carry ?type=). On create, fall back to the URL-provided type.
  const effectiveType = isEditMode ? (formData.type || projectType) : projectType;
  const isImprovement = effectiveType === 'improvement';
  const isNewProject = !isImprovement;

  // The form is always a single page now (no stepper). These flags select which
  // single-page layout to render based on the project type.
  const showImprovementSinglePage = isImprovement;
  const showNewProjectSinglePage = isNewProject;
  const autosaveKeyPrefix = isImprovement ? 'projects.improvement_autosave' : 'projects.project_autosave';
  const draftStatusLabel = draftSaveStatus === 'saving'
    ? t(`${autosaveKeyPrefix}_saving`)
    : draftSaveStatus === 'saved'
      ? draftSavedAt
        ? t(`${autosaveKeyPrefix}_saved_at`, { time: draftSavedAt })
        : t(`${autosaveKeyPrefix}_saved`)
      : draftSaveStatus === 'restored'
        ? t(`${autosaveKeyPrefix}_restored`)
        : draftSaveStatus === 'error'
          ? t(`${autosaveKeyPrefix}_error`)
          : t(`${autosaveKeyPrefix}_ready`);
  const draftHintKey = isImprovement ? 'projects.improvement_autosave_hint' : 'projects.project_autosave_hint';
  const createProjectTypeLabel = isImprovement ? t('nav.improvement_projects') : t('projects.new_project');
  const pageTitle = isEditMode ? t('projects.edit') : createProjectTypeLabel;
  const pageDescription = isEditMode ? t('projects.update_data') : t(draftHintKey);
  const renderStaticSection = (
    id: string,
    title: React.ReactNode,
    Icon: TablerIcon,
    iconTone: SectionHeaderIconTone,
    children: React.ReactNode,
    summary?: React.ReactNode,
    sectionClassName?: string
  ) => {
    return (
      <section
        id={id}
        className={`scroll-mt-20 space-y-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-subtle)] p-3 sm:p-4${sectionClassName ? ` ${sectionClassName}` : ''}`}
      >
        <SectionHeader
          title={title}
          icon={Icon}
          iconTone={iconTone}
          size="compact"
          meta={summary != null ? (
            <span className="rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs font-medium text-[var(--text-secondary)]">
              {summary}
            </span>
          ) : undefined}
          className="border-b border-[var(--border-default)] pb-2"
        />
        {children}
      </section>
    );
  };
  // Builds a section heading with an inline "?" help affordance. Used for the
  // entity sections (team, stakeholders, milestones, tasks, risks) whose
  // individual sub-fields are explained at the section level.
  const sectionTitle = (text: string, helpKey: string) => (
    <span className="inline-flex items-center gap-1.5">
      {text}
      <FieldHelp content={t(helpKey)} />
    </span>
  );
  const newProjectNavSections = [
    { id: 'section-basic', labelKey: 'projects.step_basic_info' },
    { id: 'section-charter', labelKey: 'projects.pmbok_charter_fields' },
    { id: 'section-objectives', labelKey: 'projects.step_objectives_scope' },
    { id: 'section-team', labelKey: 'projects.team' },
    { id: 'section-stakeholders', labelKey: 'projects.stakeholders' },
    { id: 'section-milestones', labelKey: 'projects.milestones' },
    { id: 'section-tasks', labelKey: 'projects.tasks' },
    { id: 'section-risks', labelKey: 'projects.risks' },
    { id: 'section-resources', labelKey: 'projects.resources_and_support' },
  ];
  // Per-section completion flags, in the same order as `newProjectNavSections`.
  // Memoized over formData to avoid recompute churn on every keystroke.
  // Content-based completion: the array fields initialize with a single empty
  // placeholder row, so `length > 0` is always true. Match the per-section
  // summary predicates (real content) instead, so a blank form reads 0%.
  const sectionCompletion = useMemo(
    () => [
      formData.name.trim() !== '',
      (formData.business_case || formData.manager_authority).trim() !== '',
      [...formData.objectives, ...formData.in_scope, ...formData.out_of_scope].some((v) => v.trim() !== ''),
      formData.team_members.some((m) => m.user_id),
      formData.stakeholders.some((s) => s.name?.trim()),
      formData.milestones.some((m) => m.name?.trim()),
      formData.tasks.some((t) => t.name?.trim()),
      formData.risks.some((r) => r.description?.trim()),
      (formData.human_resources || formData.technical_resources || formData.financial_resources).trim() !== '',
    ],
    [formData]
  );
  const completedSectionCount = sectionCompletion.filter(Boolean).length;
  const completionPct = Math.round((completedSectionCount / newProjectNavSections.length) * 100);
  const scrollToSection = (sectionId: string) => {
    window.requestAnimationFrame(() => {
      const element = document.getElementById(sectionId);
      if (element) {
        const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
      }
    });
  };
  // CTAs for the consolidated sticky bar. RTL: this group is the first child of
  // the bar row, so it lands at the inline-start (right). DOM order primary then
  // cancel => primary renders rightmost, cancel immediately to its left.
  const formActions = (
    <div className="flex items-center gap-2">
      <Button
        type="button"
        size="md"
        onClick={handleSubmit}
        loading={isSaving}
        leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
      >
        {isEditMode ? t('common.save_changes') : t('projects.create')}
      </Button>
      {!isEditMode && (
        <Button
          type="button"
          size="md"
          variant="outline"
          onClick={handleSaveDraft}
          loading={isSaving}
        >
          {t('projects.save_as_draft')}
        </Button>
      )}
      <Link to="/projects">
        <Button type="button" size="md" variant="ghost" className="text-[var(--text-secondary)]">
          {t('common.cancel')}
        </Button>
      </Link>
    </div>
  );
  // Single sticky toolbar that sits directly under the app header. The app
  // header (`.nasaq-topbar`) lives OUTSIDE the scroll container (`.nasaq-content`),
  // so `top-0` here pins the bar flush beneath it. Layout (RTL): CTAs on the
  // right, section tabs filling the center, a thin 4px progress bar underneath.
  const topActionBar = (
    <div className="sticky top-0 z-30 overflow-hidden rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] shadow-md lg:-top-[18px]">
      <div className="flex h-14 items-center gap-3 px-3">
        <div className="shrink-0">{formActions}</div>
        {showNewProjectSinglePage && (
          <nav
            aria-label={t('projects.section_nav_label')}
            className="-mx-1 min-w-0 flex-1 overflow-x-auto px-1"
          >
            <div className="flex min-w-max items-center justify-center gap-2">
              {newProjectNavSections.map((section, index) => {
                const isComplete = sectionCompletion[index];
                return (
                  <button
                    key={section.id}
                    type="button"
                    onClick={() => scrollToSection(section.id)}
                    className="shrink-0 whitespace-nowrap rounded-full border border-[var(--border-default)] bg-[var(--surface-subtle)] px-3 py-1.5 text-xs font-medium text-[var(--text-secondary)] transition-colors hover:border-[var(--border-strong)] hover:text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent-default)]"
                  >
                    {isComplete && (
                      <IconCheck className="h-3.5 w-3.5 text-[var(--status-success-text)] inline me-1" aria-hidden="true" />
                    )}
                    {t(section.labelKey)}
                  </button>
                );
              })}
            </div>
          </nav>
        )}
      </div>
      {showNewProjectSinglePage && (
        <div
          className="h-1 w-full bg-[var(--surface-muted)]"
          role="progressbar"
          aria-valuenow={completionPct}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={t('projects.section_nav_label')}
        >
          <div
            className="h-full bg-[var(--accent-default)] transition-all"
            style={{ width: `${completionPct}%` }}
          />
        </div>
      )}
    </div>
  );
  const autosaveStrip = (
    <div className="flex flex-col gap-3 rounded-lg border border-[var(--border-strong)] bg-[var(--surface-subtle)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)]">
          <IconDeviceFloppy className="h-4 w-4 text-[var(--accent-default)]" />
        </span>
        <p className="text-sm font-semibold text-[var(--text-primary)]">{draftStatusLabel}</p>
      </div>
      <p className="text-sm text-[var(--text-secondary)] sm:max-w-md sm:text-end">
        {t(draftHintKey)}
      </p>
    </div>
  );

  // User selection handlers (existing users only)
  const handleSelectStakeholder = (index: number, userId: number) => {
    const user = users.find((u) => u.id === userId);
    updateStakeholder(index, userId, user?.name ?? '');
  };

  const handleSelectTeamMember = (index: number, userId: number) => {
    const user = users.find((u) => u.id === userId);
    updateTeamMember(index, userId, user?.name ?? '');
  };

  const handleSelectTaskAssignee = (index: number, userId: number) => {
    const user = users.find((u) => u.id === userId);
    updateTaskAssignee(index, userId, user?.name ?? '');
  };

  const handleFormKeyDown = (event: React.KeyboardEvent<HTMLFormElement>) => {
    if (event.key !== 'Enter') return;

    const target = event.target as HTMLElement | null;
    if (!target || target.isContentEditable || target.closest('[contenteditable="true"]')) return;

    if (target.closest('textarea, button, [role="button"], [role="listbox"], [role="dialog"]')) {
      return;
    }

    if (target instanceof HTMLInputElement) {
      const singleLineInputTypes = new Set([
        'date',
        'datetime-local',
        'email',
        'month',
        'number',
        'password',
        'search',
        'tel',
        'text',
        'time',
        'url',
        'week',
      ]);

      if (singleLineInputTypes.has(target.type)) {
        event.preventDefault();
      }
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Card>
          <CardContent className="p-6 space-y-4">
            {[...Array(6)].map((_, i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-4 sm:p-5">
        <PageHeader
          icon={IconLayoutKanban}
          iconTone="project"
          size="compact"
          title={pageTitle}
          description={pageDescription}
          breadcrumb={
            <span className="flex flex-wrap items-center gap-2">
              <Link
                to="/projects"
                className="transition-colors hover:text-[var(--accent-default)]"
              >
                {t('projects.title')}
              </Link>
              <IconArrowRight className="h-4 w-4 rotate-180" />
              <span className="text-[var(--text-primary)]">{pageTitle}</span>
            </span>
          }
          status={
            <div className="flex flex-wrap items-center gap-2">
              <span className="rounded-full border border-[var(--border-default)] bg-[var(--surface-subtle)] px-2 py-1 text-xs font-semibold text-[var(--text-secondary)]">
                {t('projects.title')}
              </span>
              <span className="rounded-full border border-[var(--border-default)] bg-[var(--surface-muted)] px-2 py-1 text-xs font-semibold text-[var(--text-primary)]">
                {createProjectTypeLabel}
              </span>
            </div>
          }
        />
      </div>

      <form onSubmit={handleSubmit} onKeyDown={handleFormKeyDown} className="space-y-6">
        {/* Top action bar (tabs + actions) */}
        {topActionBar}

        {/* Improvement project — single page */}
        {showImprovementSinglePage && (
          <div className="space-y-4">
            {!isEditMode && autosaveStrip}

            <Card className="p-0">
              <CardContent className="p-4 sm:p-5 lg:p-6">
                <div className="space-y-4 sm:space-y-5">
                  <BasicInfoStep
                    formData={formData}
                    allDepartments={allDepartments}
                    programs={programs}
                    errors={errors}
                    onChangeField={handleChange}
                    isSelfManager={isSelfManager}
                    setIsSelfManager={setIsSelfManager}
                    assignedManagerId={assignedManagerId}
                    setAssignedManagerId={setAssignedManagerId}
                    assignableManagers={assignableManagers}
                    isLoadingAssignableManagers={isLoadingAssignableManagers}
                    isEditMode={isEditMode}
                    hideDescription
                    compact
                  />

                  <ImprovementFormSteps
                    formData={formData}
                    errors={errors}
                    handleChange={handleChange}
                    users={users}
                    addTeamMember={addTeamMember}
                    removeTeamMember={removeTeamMember}
                    updateTeamMember={updateTeamMember}
                    onKpiChange={handleKpiChange}
                    onAddKpi={addKpi}
                    onRemoveKpi={removeKpi}
                  />
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {showNewProjectSinglePage && (
          <div className="space-y-4">
            {!isEditMode && autosaveStrip}

            <Card className="p-0">
              <CardContent className="p-4 sm:p-5 lg:p-6">
                <div className="grid grid-cols-1 gap-4 sm:gap-5 lg:grid-cols-2 lg:items-start">
                  {renderStaticSection('section-basic', `1. ${t('projects.step_basic_info')}`, IconInfoCircle, 'project', (
                    <BasicInfoStep
                      formData={formData}
                      allDepartments={allDepartments}
                      programs={programs}
                      errors={errors}
                      onChangeField={handleChange}
                      isSelfManager={isSelfManager}
                      setIsSelfManager={setIsSelfManager}
                      assignedManagerId={assignedManagerId}
                      setAssignedManagerId={setAssignedManagerId}
                      assignableManagers={assignableManagers}
                      isLoadingAssignableManagers={isLoadingAssignableManagers}
                      isEditMode={isEditMode}
                      compact
                      narrow
                      bare
                    />
                  ), undefined, 'lg:col-span-2 border-[var(--accent-muted)] ring-1 ring-[var(--accent-subtle)]')}

                  {renderStaticSection('section-charter', `2. ${t('projects.pmbok_charter_fields')}`, IconLayoutKanban, 'project', (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div>
                        <div className="mb-1 flex items-center gap-1">
                          <label className="block text-sm font-medium text-[var(--text-primary)]">
                            {t('projects.business_case')}
                          </label>
                          <FieldHelp content={t('projects.business_case_help')} />
                        </div>
                        <Textarea
                          value={formData.business_case}
                          onChange={(e) => handleChange('business_case', e.target.value)}
                          placeholder={t('projects.business_case_placeholder')}
                          rows={4}
                          error={errors.business_case?.[0]}
                        />
                      </div>
                      <div>
                        <div className="mb-1 flex items-center gap-1">
                          <label className="block text-sm font-medium text-[var(--text-primary)]">
                            {t('projects.manager_authority')}
                          </label>
                          <FieldHelp content={t('projects.manager_authority_help')} />
                        </div>
                        <Textarea
                          value={formData.manager_authority}
                          onChange={(e) => handleChange('manager_authority', e.target.value)}
                          placeholder={t('projects.manager_authority_placeholder')}
                          rows={4}
                          error={errors.manager_authority?.[0]}
                        />
                      </div>
                    </div>
                  ), undefined, 'lg:col-span-2')}

                  {renderStaticSection('section-objectives', `3. ${t('projects.step_objectives_scope')}`, IconTarget, 'project', (
                    <div className="space-y-4">
                      <div className="flex items-center gap-1">
                        <span className="block text-sm font-medium text-[var(--text-primary)]">
                          {t('projects.objectives')}
                        </span>
                        <FieldHelp content={t('projects.help.objectives')} />
                      </div>
                      <ObjectivesSection
                        objectives={formData.objectives}
                        onObjectiveChange={(index, value) => handleArrayItemChange('objectives', index, value)}
                        onAddObjective={() => addArrayItem('objectives')}
                        onRemoveObjective={(index) => removeArrayItem('objectives', index)}
                        compact
                      />
                      <ScopeSection
                        inScope={formData.in_scope}
                        outOfScope={formData.out_of_scope}
                        onInScopeChange={(index, value) => handleArrayItemChange('in_scope', index, value)}
                        onOutOfScopeChange={(index, value) => handleArrayItemChange('out_of_scope', index, value)}
                        onAddInScope={() => addArrayItem('in_scope')}
                        onAddOutOfScope={() => addArrayItem('out_of_scope')}
                        onRemoveInScope={(index) => removeArrayItem('in_scope', index)}
                        onRemoveOutOfScope={(index) => removeArrayItem('out_of_scope', index)}
                        compact
                      />
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                          <div className="mb-1 flex items-center gap-1">
                            <label className="block text-sm font-medium text-[var(--text-primary)]">
                              {t('projects.success_criteria')}
                            </label>
                            <FieldHelp content={t('projects.success_criteria_help')} />
                          </div>
                          <Textarea
                            value={formData.success_criteria}
                            onChange={(e) => handleChange('success_criteria', e.target.value)}
                            placeholder={t('projects.success_criteria_placeholder')}
                            rows={2}
                            error={errors.success_criteria?.[0]}
                          />
                        </div>
                        <div>
                          <div className="mb-1 flex items-center gap-1">
                            <label className="block text-sm font-medium text-[var(--text-primary)]">
                              {t('projects.requirements')}
                            </label>
                            <FieldHelp content={t('projects.requirements_help')} />
                          </div>
                          <Textarea
                            value={formData.requirements}
                            onChange={(e) => handleChange('requirements', e.target.value)}
                            placeholder={t('projects.requirements_placeholder')}
                            rows={2}
                            error={errors.requirements?.[0]}
                          />
                        </div>
                        <div>
                          <div className="mb-1 flex items-center gap-1">
                            <label className="block text-sm font-medium text-[var(--text-primary)]">
                              {t('projects.approval_criteria')}
                            </label>
                            <FieldHelp content={t('projects.approval_criteria_help')} />
                          </div>
                          <Textarea
                            value={formData.approval_criteria}
                            onChange={(e) => handleChange('approval_criteria', e.target.value)}
                            placeholder={t('projects.approval_criteria_placeholder')}
                            rows={2}
                            error={errors.approval_criteria?.[0]}
                          />
                        </div>
                        <div>
                          <div className="mb-1 flex items-center gap-1">
                            <label className="block text-sm font-medium text-[var(--text-primary)]">
                              {t('projects.exit_criteria')}
                            </label>
                            <FieldHelp content={t('projects.exit_criteria_help')} />
                          </div>
                          <Textarea
                            value={formData.exit_criteria}
                            onChange={(e) => handleChange('exit_criteria', e.target.value)}
                            placeholder={t('projects.exit_criteria_placeholder')}
                            rows={2}
                            error={errors.exit_criteria?.[0]}
                          />
                        </div>
                      </div>
                    </div>
                  ), undefined, 'lg:col-span-2')}

                  {renderStaticSection('section-team', sectionTitle(`4. ${t('projects.team')}`, 'projects.help.team'), IconUsers, 'project', (
                    <TeamSection
                      teamMembers={formData.team_members}
                      users={users}
                      onAddTeamMember={addTeamMember}
                      onRemoveTeamMember={removeTeamMember}
                      onSelectTeamMember={handleSelectTeamMember}
                      compact
                    />
                  ), formData.team_members.filter((m) => m.user_id).length || undefined)}

                  {renderStaticSection('section-stakeholders', sectionTitle(`5. ${t('projects.stakeholders')}`, 'projects.help.stakeholders'), IconUserCheck, 'project', (
                    <StakeholdersSection
                      stakeholders={formData.stakeholders}
                      users={users}
                      onStakeholderChange={handleStakeholderChange}
                      onAddStakeholder={addStakeholder}
                      onRemoveStakeholder={removeStakeholder}
                      onSelectStakeholder={handleSelectStakeholder}
                      compact
                    />
                  ), formData.stakeholders.filter((s) => s.name?.trim()).length || undefined)}

                  {renderStaticSection('section-milestones', sectionTitle(`6. ${t('projects.milestones')}`, 'projects.help.milestones'), IconFlag, 'project', (
                    <MilestonesSection
                      milestones={formData.milestones}
                      projectStartDate={formData.start_date}
                      projectEndDate={formData.end_date}
                      onMilestoneChange={handleMilestoneChange}
                      onAddMilestone={addMilestone}
                      onRemoveMilestone={removeMilestone}
                      onSuggestMilestones={suggestMilestones}
                      onDeliverableChange={handleDeliverableChange}
                      onAddDeliverable={addDeliverable}
                      onRemoveDeliverable={removeDeliverable}
                      compact
                    />
                  ), formData.milestones.filter((m) => m.name?.trim()).length || undefined)}

                  {renderStaticSection('section-tasks', sectionTitle(`7. ${t('projects.tasks')}`, 'projects.help.tasks'), IconClipboardList, 'project', (
                    <InlineTasksEditor
                      tasks={formData.tasks}
                      milestones={formData.milestones}
                      users={users}
                      projectStartDate={formData.start_date}
                      projectEndDate={formData.end_date}
                      onTaskChange={handleTaskChange}
                      onAddTask={addTask}
                      onRemoveTask={removeTask}
                      onSelectAssignee={handleSelectTaskAssignee}
                      compact
                    />
                  ), formData.tasks.filter((task) => task.name?.trim()).length || undefined)}

                  {renderStaticSection('section-risks', sectionTitle(`8. ${t('projects.risks')}`, 'projects.help.risks'), IconAlertTriangle, 'risk', (
                    <RisksSection
                      risks={formData.risks}
                      onRiskChange={handleRiskChange}
                      onAddRisk={addRisk}
                      onRemoveRisk={removeRisk}
                      compact
                    />
                  ), formData.risks.filter((r) => r.description?.trim()).length || undefined)}

                  {renderStaticSection('section-resources', `9. ${t('projects.resources_and_support')}`, IconBriefcase, 'project', (
                    <ResourcesSection
                      humanResources={formData.human_resources}
                      technicalResources={formData.technical_resources}
                      financialResources={formData.financial_resources}
                      onHumanResourcesChange={(value) => handleChange('human_resources', value)}
                      onTechnicalResourcesChange={(value) => handleChange('technical_resources', value)}
                      onFinancialResourcesChange={(value) => handleChange('financial_resources', value)}
                      compact
                      stacked
                    />
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        )}

      </form>
    </div>
  );
};

export default ProjectForm;
