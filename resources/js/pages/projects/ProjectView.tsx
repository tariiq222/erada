import React, { useEffect, useState, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { projectsApi } from '@entities/project';
import { formatDateShort } from '@shared/lib/utils';
import {
  Badge,
  Button,
  PageHeader,
  StatusBadge,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  Breadcrumb,
  Modal,
  ModalBody,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconEdit, IconCalendar, IconUser, IconBuilding, IconTarget, IconListCheck, IconUsers, IconAlertTriangle, IconTrendingUp, IconCurrencyDollar, IconFileText, IconHistory, IconUserCheck, IconNotebook, IconCircleX, IconBriefcase} from '@tabler/icons-react';
import { IconClipboardCheck } from '@shared/ui/icons';
import { useCan } from '@shared/api/access';
import { DecisionsSection } from '@features/meetings';
import ProjectReportCard from '@widgets/project/ui/ProjectReportCard';
import ProjectActivityLog from '@widgets/project/ui/ProjectActivityLog';
import ProjectExpenses from '@features/project-expenses/ui/ProjectExpenses';
import { ProjectCharter, type ProjectCharterProject } from './charter/ProjectCharter';
import { ClosureModal } from './closure/ClosureModal';
import type { ClosureData } from './closure/ClosureModal';

// Import types
import type { ProjectDetails } from './types';

// Import components
import ProjectStatsCard from './components/ProjectStatsCard';
import ProjectViewSkeleton from './components/ProjectViewSkeleton';
import { OverviewTab } from './components/tabs';
import {
  TeamSection,
  StakeholdersSection,
  KPIsSection,
  RisksSection,
  ProjectTasksSection,
} from './components/sections';
import GoldenChainTag from '@pages/strategy/components/GoldenChainTag';
import PdcaStepper from './components/pdca/PdcaStepper';

// ─── Mapper: API (snake_case) → ProjectCharterProject (camelCase flat) ──────

function mapProjectToCharter(project: ProjectDetails): ProjectCharterProject {
  const type: 'development' | 'improvement' = project.type === 'improvement' ? 'improvement' : 'development';

  // Format arrays (objectives, in_scope, out_of_scope) as newline-separated strings
  const joinArray = (arr: string[] | null | undefined): string | undefined =>
    arr && arr.length > 0 ? arr.join('\n') : undefined;

  // Build milestone list for charter
  const milestones = project.milestones.map((m) => ({
    name: m.name,
    deliverable: m.deliverables?.map((d) => d.name).join(', ') || '',
    date: m.due_date || '',
  }));

  // Collect team members as a summary string
  const teamMembers = project.members.map((m) => m.name).join('، ') || undefined;

  // Collect stakeholders summary
  const stakeholders = project.stakeholders
    .map((s) => `${s.name} (${s.role})`)
    .join('، ') || undefined;

  // Collect risks summary
  const risks = project.risks
    .map((r) => r.risk)
    .join('، ') || undefined;

  return {
    type,
    name: project.name,
    projectCode: project.code,
    startDate: project.start_date ?? undefined,
    endDate: project.end_date ?? undefined,
    status: project.status,
    description: project.description ?? undefined,
    department: project.department?.name,
    priority: project.priority,
    budget: project.budget != null ? String(project.budget) : undefined,

    // PMBOK / new project fields. success_criteria / requirements /
    // manager_authority arrive as arrays (backend casts); pass them through —
    // the charter components accept arrays and normalize internally.
    businessCase: project.business_case ?? undefined,
    successCriteria: project.success_criteria ?? undefined,
    highLevelRequirements: project.requirements ?? undefined,
    managerAuthority: project.manager_authority ?? undefined,
    approvalRequirements: project.approval_criteria ?? undefined,
    exitCriteria: project.exit_criteria ?? undefined,
    humanResources: project.human_resources ?? undefined,
    technicalResources: project.technical_resources ?? undefined,
    financialResources: project.financial_resources ?? undefined,
    objectives: joinArray(project.objectives as string[] | null),
    scopeIncluded: joinArray(project.in_scope as string[] | null),
    scopeExcluded: joinArray(project.out_of_scope as string[] | null),

    // FOCUS-PDCA / improvement project fields
    problemStatement: project.problem_statement ?? undefined,
    targetProcess: project.target_process ?? undefined,
    rootCauses: project.root_cause ?? undefined,
    expectedBenefits: project.expected_benefits ?? undefined,

    // People. The sponsor relation was removed (column dropped); only the
    // project manager (scoped role) is resolved now.
    managerName: project.manager?.name,
    teamMembers,
    stakeholders,

    // Milestones & risks
    milestones,
    risks,
  };
}

const ProjectView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const canEditProject = useCan('projects.edit');
  // Canonical member-management gate. `projects.manage_members` was the legacy
  // capability (audit 2026-07-06) — it is no longer enforced; the engine routes
  // member / role assignment through `projects.assign_roles` (unified with
  // `/projects/{id}/roles/*`). The access-bridge keeps a 1-version sunset
  // mapping for any stale session still carrying the old key.
  const canManageMembers = useCan('projects.assign_roles');
  const canManageKpis = useCan('kpis.manage');
  const canViewStrategy = useCan('strategy.view');
  const canCreateStrategy = useCan('strategy.create');
  const canEditStrategy = useCan('strategy.edit');
  const { showToast } = useToast();
  const [project, setProject] = useState<ProjectDetails | null>(null);
  const [loading, setLoading] = useState(true);
  // Distinct from not-found: a load failure (permission/server/network) must
  // not be rendered as a 404 "project not found" page.
  const [loadError, setLoadError] = useState(false);
  const [showCharter, setShowCharter] = useState(false);
  const [showClosure, setShowClosure] = useState(false);
  const [performanceKpiTabCount, setPerformanceKpiTabCount] = useState<{ projectId: number; count: number } | null>(null);
  const currentProjectId = project?.id;

  const fetchProject = useCallback(async () => {
    setLoadError(false);
    try {
      const data = await projectsApi.getOne(Number(id));
      setProject(data as ProjectDetails);
    } catch (error) {
      console.error('Failed to fetch project:', error);
      // Surface as a load error (with retry), not as a not-found page.
      setLoadError(true);
    } finally {
      setLoading(false);
    }
  }, [id]);

  const handleClosureComplete = useCallback(async (closureData: ClosureData) => {
    if (!project) return;
    try {
      await projectsApi.update(project.id, {
        status: 'completed',
        ...closureData,
      } as Parameters<typeof projectsApi.update>[1]);
      setShowClosure(false);
      showToast('success', t('projects.close_success'));
      fetchProject();
    } catch (error: unknown) {
      const msg = error instanceof Error ? error.message : t('projects.close_failed');
      showToast('error', msg);
    }
  }, [project, fetchProject, showToast, t]);

  const handleKPICountChange = useCallback((count: number) => {
    if (currentProjectId == null) return;

    setPerformanceKpiTabCount({ projectId: currentProjectId, count });
  }, [currentProjectId]);

  useEffect(() => {
    fetchProject();
  }, [id]);

  if (loading) {
    return <ProjectViewSkeleton />;
  }

  if (loadError) {
    return (
      <div className="text-center py-12">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">{t('projects.load_error_title')}</h2>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">{t('projects.load_error_description')}</p>
        <div className="mt-4 flex items-center justify-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setLoading(true);
              fetchProject();
            }}
          >
            {t('common.retry')}
          </Button>
          <Link to="/projects" className="text-[var(--accent-default)] hover:underline">
            {t('projects.back_to_projects')}
          </Link>
        </div>
      </div>
    );
  }

  if (!project) {
    return (
      <div className="text-center py-12">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">{t('projects.not_found')}</h2>
        <Link to="/projects" className="text-[var(--accent-default)] hover:underline mt-2 inline-block">
          {t('projects.back_to_projects')}
        </Link>
      </div>
    );
  }

  const kpiTabCount = performanceKpiTabCount?.projectId === project.id
    ? performanceKpiTabCount.count
    : project.kpis.length;

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        icon={IconBriefcase}
        iconTone="project"
        iconVariant="subtle"
        title={project.name}
        breadcrumb={
          <Breadcrumb
            items={[
              { label: t('projects.title'), href: '/projects' },
              { label: project.name },
            ]}
          />
        }
        status={<StatusBadge type="project" status={project.status} size="sm" />}
        metadata={
          <div className="flex flex-wrap items-center gap-2 text-xs">
            {/* رقم المشروع */}
            <Badge variant="default" size="sm" className="gap-1">
              {project.code}
            </Badge>

            {/* القسم - هوية المشروع */}
            <Badge variant="accent" size="sm" className="gap-1">
              <IconBuilding className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="font-medium">{project.department?.name || t('projects.no_department')}</span>
            </Badge>

            {/* مدير المشروع */}
            <Badge variant="accent" size="sm" className="gap-1">
              <IconUser className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="text-xs opacity-80">{t('projects.leader')}:</span>
              <span className="font-medium">{project.manager?.name || t('projects.not_assigned')}</span>
            </Badge>

            {/* التواريخ */}
            <Badge variant="accent" size="sm" className="gap-1">
              <IconCalendar className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="font-medium">
                {project.start_date ? formatDateShort(project.start_date) : '-'}
                {project.end_date && ` – ${formatDateShort(project.end_date)}`}
              </span>
            </Badge>

            {/* Strategic linkage (PMI Golden Chain) - pill placed next to dates */}
            <GoldenChainTag type="project" id={project.id} />
          </div>
        }
        actions={
          <>
            <Button
              variant="outline"
              size="sm"
              leftIcon={<IconNotebook className="h-4 w-4" />}
              onClick={() => setShowCharter(true)}
            >
              {t('projects.generate_charter')}
            </Button>
            {(project.status === 'in_progress' || project.status === 'on_hold') && (
              <Button
                variant="outline"
                size="sm"
                leftIcon={<IconCircleX className="h-4 w-4" />}
                onClick={() => setShowClosure(true)}
              >
                {t('projects.close_project')}
              </Button>
            )}
            <Link to={`/projects/${project.id}/edit`}>
              <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                {t('common.edit')}
              </Button>
            </Link>
          </>
        }
      />

      {/* Tabs */}
      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview" icon={<IconTarget className="h-4 w-4" />}>
            {t('projects.overview')}
          </TabsTrigger>
          <TabsTrigger value="tasks" icon={<IconListCheck className="h-4 w-4" />}>
            {t('projects.tasks')} ({project.tasks.length})
          </TabsTrigger>
          <TabsTrigger value="team" icon={<IconUsers className="h-4 w-4" />}>
            {t('projects.team')} ({project.members.length})
          </TabsTrigger>
          <TabsTrigger value="stakeholders" icon={<IconUserCheck className="h-4 w-4" />}>
            {t('projects.stakeholders')} ({project.stakeholders.length})
          </TabsTrigger>
          <TabsTrigger value="risks" icon={<IconAlertTriangle className="h-4 w-4" />}>
            {t('projects.risks')} ({project.risks.length})
          </TabsTrigger>
          <TabsTrigger value="kpis" icon={<IconTrendingUp className="h-4 w-4" />}>
            {t('projects.kpis')} ({kpiTabCount})
          </TabsTrigger>
          <TabsTrigger value="expenses" icon={<IconCurrencyDollar className="h-4 w-4" />}>
            {t('projects.expenses')}
          </TabsTrigger>
          <TabsTrigger value="report-card" icon={<IconFileText className="h-4 w-4" />}>
            {t('projects.report_card')}
          </TabsTrigger>
          <TabsTrigger value="activity-log" icon={<IconHistory className="h-4 w-4" />}>
            {t('projects.activity_log')}
          </TabsTrigger>
          <TabsTrigger value="decisions" icon={<IconClipboardCheck className="h-4 w-4" />}>
            {t('strategy.decisions.title')}
          </TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview">
          {/* Project Statistics Card - moved into the Overview tab */}
          <ProjectStatsCard project={project} />

          {project.type === 'improvement' && (
            <div className="mb-4">
              <PdcaStepper project={project} onChanged={fetchProject} />
            </div>
          )}
          <OverviewTab project={project} />
        </TabsContent>

        {/* Tasks Tab */}
        <TabsContent value="tasks">
          <ProjectTasksSection
            tasks={project.tasks}
            projectId={project.id}
            projectManagerId={project.manager?.id}
            members={project.members}
            project={project}
            onTaskCreated={fetchProject}
          />
        </TabsContent>

        {/* Team Tab */}
        <TabsContent value="team">
          <TeamSection
            members={project.members}
            projectId={project.id}
            onMemberAdded={fetchProject}
            onMemberRemoved={fetchProject}
            canEdit={canEditProject || canManageMembers}
          />
        </TabsContent>

        {/* Stakeholders Tab */}
        <TabsContent value="stakeholders">
          <StakeholdersSection
            stakeholders={project.stakeholders}
            projectId={project.id}
            onStakeholderAdded={fetchProject}
            onStakeholderRemoved={fetchProject}
            onStakeholderUpdated={fetchProject}
            canEdit={canEditProject}
          />
        </TabsContent>

        {/* Risks Tab */}
        <TabsContent value="risks">
          <RisksSection
            risks={project.risks}
            projectId={project.id}
            onRiskAdded={fetchProject}
            onRiskRemoved={fetchProject}
            canEdit={canEditProject}
          />
        </TabsContent>

        {/* KPIs Tab */}
        <TabsContent value="kpis">
          <KPIsSection
            kpis={project.kpis}
            projectId={project.id}
            organizationId={project.organization_id}
            onKPIAdded={fetchProject}
            onKPIRemoved={fetchProject}
            onKPICountChange={handleKPICountChange}
            canEdit={canManageKpis}
          />
        </TabsContent>

        {/* Expenses Tab */}
        <TabsContent value="expenses">
          <ProjectExpenses
            projectId={project.id}
            budget={project.budget}
            tasks={project.tasks.map(t => ({ id: t.id, title: t.title }))}
            canEdit={canEditProject}
          />
        </TabsContent>

        {/* Report Card Tab */}
        <TabsContent value="report-card">
          <ProjectReportCard project={project} />
        </TabsContent>

        {/* Activity Log Tab */}
        <TabsContent value="activity-log">
          <ProjectActivityLog projectId={project.id} />
        </TabsContent>

        {/* Decisions Tab */}
        <TabsContent value="decisions">
          <DecisionsSection
            decidable_type="project"
            decidable_id={project.id}
            decidable_name={project.name}
            permissions={{
              canView: canViewStrategy,
              canCreate: canCreateStrategy,
              canEdit: canEditStrategy,
            }}
          />
        </TabsContent>
      </Tabs>

      {/* Charter Modal */}
      <Modal
        open={showCharter}
        onClose={() => setShowCharter(false)}
        title={t('projects.generate_charter')}
        size="xl"
      >
        <ModalBody>
          <ProjectCharter project={mapProjectToCharter(project)} />
        </ModalBody>
      </Modal>

      {/* Closure Modal */}
      <ClosureModal
        open={showClosure}
        project={{
          type: project.type === 'improvement' ? 'improvement' : 'development',
          name: project.name,
        }}
        openTasksCount={project.tasks.filter((task) => task.status !== 'completed' && task.status !== 'cancelled').length}
        onClose={() => setShowClosure(false)}
        onComplete={handleClosureComplete}
      />
    </div>
  );
};

export default ProjectView;
