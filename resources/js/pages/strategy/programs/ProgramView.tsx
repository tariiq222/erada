import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Breadcrumb,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  Skeleton,
  Card,
  CardContent,
  StatusBadge,
} from '@shared/ui';
import { StatCard, PageHeader } from '@shared/ui';
import {IconLayoutKanban, IconEdit, IconCalendar, IconCurrencyDollar, IconAlertTriangle, IconTarget, IconTrendingUp, IconBriefcase, IconBuilding, IconUser} from '@tabler/icons-react';
import { IconClipboardCheck } from '@shared/ui/icons';
import { useCan } from '@shared/api/access';
import { DecisionsSection } from '@features/meetings';
import {
  OverviewTab,
  ProjectsTab,
  LinkProjectModal,
  useProgramView,
  formatDate,
} from './view';

const ProgramViewSkeleton: React.FC = () => (
  <div className="space-y-4">
    <Skeleton className="h-6 w-48" />
    <div className="flex items-center gap-3">
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-6 w-20 rounded-full" />
    </div>
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      {[1, 2, 3, 4].map((i) => (
        <Card key={i}>
          <CardContent className="p-4">
            <Skeleton className="h-4 w-20 mb-2" />
            <Skeleton className="h-8 w-16" />
          </CardContent>
        </Card>
      ))}
    </div>
  </div>
);

const ProgramView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const canViewStrategy = useCan('strategy.view');
  const canCreateStrategy = useCan('strategy.create');
  const canEditStrategy = useCan('strategy.edit');

  const {
    // State
    program,
    projects,
    unlinkedProjects,
    loading,
    showLinkModal,
    linkingProjectId,
    defaultTab,

    // Computed
    completedProjects,
    inProgressProjects,
    avgProgress,

    // Handlers
    setShowLinkModal,
    handleLinkProject,
    handleUnlinkProject,
    openLinkModal,
  } = useProgramView({ id });

  if (loading) {
    return <ProgramViewSkeleton />;
  }

  if (!program) {
    return (
      <div className="text-center py-12">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">{t('strategy.program_not_found')}</h2>
        <Link to="/strategy/programs" className="text-[var(--accent-default)] hover:underline mt-2 inline-block">
          {t('strategy.back_to_programs')}
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('strategy.executive_planning'), href: '/strategy' },
          { label: t('strategy.programs'), href: '/strategy/programs' },
          { label: program.name },
        ]}
      />

      {/* Header */}
      <PageHeader
        title={program.name}
        subtitle={program.code}
        iconTone="project"
        actions={
          <>
            <StatusBadge type="project" status={program.status} size="sm" />
            <StatusBadge type="priority" status={program.priority} size="sm" />
            <Link to={`/strategy/programs/${program.id}/edit`}>
              <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                {t('common.edit')}
              </Button>
            </Link>
          </>
        }
      />

      {/* Info Bar with Tags */}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {/* الالتزام التنفيذي */}
        {program.portfolio && (
          <Link
            to={`/strategy/portfolios/${program.portfolio.id}`}
            className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)] border border-[var(--accent-muted)] hover:bg-[var(--accent-muted)] transition-colors"
          >
            <IconBriefcase className="h-3.5 w-3.5" />
            <span className="font-medium">{program.portfolio.name}</span>
          </Link>
        )}

        {/* القسم */}
        {program.department && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)]">
            <IconBuilding className="h-3.5 w-3.5" />
            <span className="font-medium">{program.department.name}</span>
          </div>
        )}

        {/* مدير المبادرة */}
        {program.program_manager && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)]">
            <IconUser className="h-3.5 w-3.5" />
            <span className="text-xs text-[var(--text-tertiary)]">{t('strategy.manager')}:</span>
            <span className="font-medium">{program.program_manager.name}</span>
          </div>
        )}

        {/* التواريخ */}
        {(program.start_date || program.end_date) && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)]">
            <IconCalendar className="h-3.5 w-3.5" />
            <span className="font-medium">
              {formatDate(program.start_date)}
              {program.end_date && ` – ${formatDate(program.end_date)}`}
            </span>
          </div>
        )}
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4">
        <StatCard
          label={t('strategy.projects')}
          value={program.projects_count}
          icon={IconLayoutKanban}
          color="accent"
        />
        <StatCard
          label={t('strategy.completion_rate')}
          value={`${program.progress}%`}
          icon={IconTrendingUp}
          color="success"
        />
        <StatCard
          label={t('strategy.budget')}
          value={program.budget ? `${(program.budget / 1000).toFixed(0)}K` : '-'}
          icon={IconCurrencyDollar}
          color="warning"
        />
        <StatCard
          label={t('strategy.blockers')}
          value={program.blockers_count}
          icon={IconAlertTriangle}
          color="danger"
        />
      </div>

      {/* Tabs */}
      <Tabs defaultValue={defaultTab}>
        <TabsList>
          <TabsTrigger value="overview" icon={<IconTarget className="h-4 w-4" />}>
            {t('common.overview')}
          </TabsTrigger>
          <TabsTrigger value="projects" icon={<IconLayoutKanban className="h-4 w-4" />}>
            {t('strategy.projects')} ({projects.length})
          </TabsTrigger>
          <TabsTrigger value="decisions" icon={<IconClipboardCheck className="h-4 w-4" />}>
            {t('strategy.decisions.title')}
          </TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview">
          <OverviewTab
            program={program}
            projectsCount={projects.length}
            inProgressProjects={inProgressProjects}
            completedProjects={completedProjects}
            avgProgress={avgProgress}
          />
        </TabsContent>

        {/* Projects Tab */}
        <TabsContent value="projects">
          <ProjectsTab
            programId={program.id}
            projects={projects}
            onOpenLinkModal={openLinkModal}
            onUnlinkProject={handleUnlinkProject}
          />
        </TabsContent>

        {/* Decisions Tab */}
        <TabsContent value="decisions">
          <DecisionsSection
            decidable_type="program"
            decidable_id={program.id}
            decidable_name={program.name}
            permissions={{
              canView: canViewStrategy,
              canCreate: canCreateStrategy,
              canEdit: canEditStrategy,
            }}
          />
        </TabsContent>
      </Tabs>

      {/* Link Project Modal */}
      <LinkProjectModal
        programId={program.id}
        isOpen={showLinkModal}
        projects={unlinkedProjects}
        linkingProjectId={linkingProjectId}
        onClose={() => setShowLinkModal(false)}
        onLinkProject={handleLinkProject}
      />
    </div>
  );
};

export default ProgramView;
