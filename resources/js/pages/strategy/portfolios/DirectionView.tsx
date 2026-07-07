import React, { useEffect, useState, useCallback } from 'react';
import { Link, useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { portfoliosApi, programsApi } from '@entities/strategy';
import {
  Button,
  Badge,
  Card,
  CardContent,
  Progress,
  Breadcrumb,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  Skeleton,
  StatusBadge,
} from '@shared/ui';
import { StatCard, StatStrip, PageHeader, Avatar } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconLayoutKanban, IconEdit, IconCalendar, IconTarget, IconBriefcase, IconBuilding, IconEye, IconFileText, IconTrendingUp, IconLink} from '@tabler/icons-react';
import { IconClipboardCheck } from '@shared/ui/icons';
import { useCan } from '@shared/api/access';
import { DecisionsSection } from '@features/meetings';
import { statusLabels, statusVariants } from './list/constants';

interface Portfolio {
  id: number;
  code: string;
  name: string;
  description: string | null;
  status: string;
  status_label: string;
  strategic_plan_link: string | null;
  directive_source: string | null;
  directive_source_other: string | null;
  directive_source_label: string | null;
  start_date: string | null;
  end_date: string | null;
  order: number;
  objectives_count: number;
  programs_count: number;
  progress: number;
}

interface Program {
  id: number;
  code: string;
  name: string;
  status: string;
  priority: string;
  progress: number;
  projects_count: number;
  department: { id: number; name: string } | null;
  program_manager: { id: number; name: string } | null;
}

const DirectionViewSkeleton: React.FC = () => (
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

const DirectionView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const canViewStrategy = useCan('strategy.view');
  const canCreateStrategy = useCan('strategy.create');
  const canEditStrategy = useCan('strategy.edit');

  const [portfolio, setPortfolio] = useState<Portfolio | null>(null);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [loading, setLoading] = useState(true);

  const defaultTab = searchParams.get('tab') || 'overview';

  const fetchPortfolio = useCallback(async () => {
    try {
      const data = (await portfoliosApi.getOne(Number(id))) as Portfolio;
      setPortfolio(data);
    } catch (error) {
      showToast('error', t('strategy.portfolio_load_error'));
      navigate('/strategy/portfolios');
    }
  }, [id, navigate, showToast]);

  const fetchPrograms = useCallback(async () => {
    try {
      const params = { portfolio_id: String(id) };
      const response = (await programsApi.getAll(params)) as { data: Program[] };
      setPrograms(response.data || []);
    } catch (error) {
      console.error('Failed to fetch programs:', error);
    }
  }, [id]);

  useEffect(() => {
    const loadData = async () => {
      setLoading(true);
      await Promise.all([fetchPortfolio(), fetchPrograms()]);
      setLoading(false);
    };
    loadData();
  }, [fetchPortfolio, fetchPrograms]);

  if (loading) {
    return <DirectionViewSkeleton />;
  }

  if (!portfolio) {
    return (
      <div className="text-center py-12">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">{t('strategy.portfolio_not_found')}</h2>
        <Link to="/strategy/portfolios" className="text-[var(--accent-default)] hover:underline mt-2 inline-block">
          {t('strategy.back_to_portfolios')}
        </Link>
      </div>
    );
  }

  // حساب إحصائيات المبادرات
  const completedPrograms = programs.filter((p) => p.status === 'completed').length;
  const activePrograms = programs.filter((p) => p.status === 'active').length;
  const totalProjects = programs.reduce((sum, p) => sum + (p.projects_count || 0), 0);

  return (
    <div className="space-y-4">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('strategy.executive_planning'), href: '/strategy' },
          { label: t('strategy.portfolios'), href: '/strategy/portfolios' },
          { label: portfolio.name },
        ]}
      />

      {/* Header */}
      <PageHeader
        title={portfolio.name}
        subtitle={portfolio.code}
        iconTone="project"
        actions={
          <>
            <Badge variant={statusVariants[portfolio.status]} size="sm">
              {t(statusLabels[portfolio.status]) || portfolio.status_label}
            </Badge>
            <Link to={`/strategy/portfolios/${portfolio.id}/edit`}>
              <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                {t('common.edit')}
              </Button>
            </Link>
          </>
        }
      />

      {/* Info Bar with Tags */}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {/* جهة التوجيه */}
        {portfolio.directive_source_label && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--support-indigo-subtle)] text-[var(--support-indigo-text)] border border-[var(--accent-muted)]">
            <IconBuilding className="h-3.5 w-3.5" />
            <span className="font-medium">{portfolio.directive_source_label}</span>
          </div>
        )}

        {/* التواريخ */}
        {(portfolio.start_date || portfolio.end_date) && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)]">
            <IconCalendar className="h-3.5 w-3.5" />
            <span className="font-medium">
              {portfolio.start_date || '-'}
              {portfolio.end_date && ` – ${portfolio.end_date}`}
            </span>
          </div>
        )}

        {/* رابط الخطة الاستراتيجية */}
        {portfolio.strategic_plan_link && (
          <a
            href={portfolio.strategic_plan_link}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)] hover:bg-[var(--surface-hover)] transition-colors"
          >
            <IconLink className="h-3.5 w-3.5" />
            <span className="font-medium">{t('strategy.strategic_plan')}</span>
          </a>
        )}
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4">
        <StatCard
          label={t('strategy.programs')}
          value={portfolio.programs_count}
          icon={IconLayoutKanban}
          color="accent"
        />
        <StatCard
          label={t('strategy.objectives')}
          value={portfolio.objectives_count}
          icon={IconTarget}
          color="success"
        />
        <StatCard
          label={t('strategy.completion_rate')}
          value={`${Math.round(portfolio.progress)}%`}
          icon={IconTrendingUp}
          color="warning"
        />
        <StatCard
          label={t('strategy.projects')}
          value={totalProjects}
          icon={IconBriefcase}
          color="info"
        />
      </div>

      {/* Tabs */}
      <Tabs defaultValue={defaultTab}>
        <TabsList>
          <TabsTrigger value="overview" icon={<IconTarget className="h-4 w-4" />}>
            {t('common.overview')}
          </TabsTrigger>
          <TabsTrigger value="programs" icon={<IconLayoutKanban className="h-4 w-4" />}>
            {t('strategy.programs')} ({programs.length})
          </TabsTrigger>
          <TabsTrigger value="decisions" icon={<IconClipboardCheck className="h-4 w-4" />}>
            {t('strategy.decisions.title')}
          </TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Main Info */}
            <div className="lg:col-span-2 space-y-6">
              {portfolio.description && (
                <Card>
                  <CardContent className="p-6">
                    <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-2">{t('common.description')}</h3>
                    <p className="text-[var(--text-primary)]">{portfolio.description}</p>
                  </CardContent>
                </Card>
              )}

              {/* نسبة الإنجاز التفصيلية */}
              <Card>
                <CardContent className="p-6">
                  <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('strategy.portfolio_progress')}</h3>
                  <div className="space-y-4">
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <span className="text-sm text-[var(--text-secondary)]">{t('strategy.overall_completion')}</span>
                        <span className="text-lg font-bold text-[var(--text-primary)]">{Math.round(portfolio.progress)}%</span>
                      </div>
                      <Progress value={portfolio.progress} size="md" />
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* ملخص المبادرات */}
              <Card>
                <CardContent className="p-6">
                  <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('strategy.programs_summary')}</h3>
                  <StatStrip
                    items={[
                      { label: t('common.total'), value: programs.length },
                      { label: t('common.active'), value: activePrograms, tone: 'accent' },
                      { label: t('status.completed'), value: completedPrograms, tone: 'success' },
                      { label: t('strategy.projects'), value: totalProjects },
                    ]}
                  />
                </CardContent>
              </Card>
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              <Card>
                <CardContent className="p-6">
                  <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('common.details')}</h3>
                  <div className="space-y-4">
                    {portfolio.start_date && (
                      <div className="flex items-center gap-3">
                        <IconCalendar className="w-4 h-4 text-[var(--text-tertiary)]" />
                        <div>
                          <p className="text-xs text-[var(--text-secondary)]">{t('common.start_date')}</p>
                          <p className="text-sm text-[var(--text-primary)]">{portfolio.start_date}</p>
                        </div>
                      </div>
                    )}
                    {portfolio.end_date && (
                      <div className="flex items-center gap-3">
                        <IconCalendar className="w-4 h-4 text-[var(--text-tertiary)]" />
                        <div>
                          <p className="text-xs text-[var(--text-secondary)]">{t('common.end_date')}</p>
                          <p className="text-sm text-[var(--text-primary)]">{portfolio.end_date}</p>
                        </div>
                      </div>
                    )}
                    {portfolio.directive_source_label && (
                      <div className="flex items-center gap-3">
                        <IconBuilding className="w-4 h-4 text-[var(--text-tertiary)]" />
                        <div>
                          <p className="text-xs text-[var(--text-secondary)]">{t('strategy.directive_source')}</p>
                          <p className="text-sm text-[var(--text-primary)]">{portfolio.directive_source_label}</p>
                        </div>
                      </div>
                    )}
                    {portfolio.strategic_plan_link && (
                      <div className="flex items-center gap-3">
                        <IconFileText className="w-4 h-4 text-[var(--text-tertiary)]" />
                        <div>
                          <p className="text-xs text-[var(--text-secondary)]">{t('strategy.strategic_plan')}</p>
                          <a
                            href={portfolio.strategic_plan_link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm text-[var(--accent-default)] hover:underline"
                          >
                            {t('common.view_link')}
                          </a>
                        </div>
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>
        </TabsContent>

        {/* Programs Tab */}
        <TabsContent value="programs">
          <div className="space-y-4">
            {/* Projects List */}
            <Card className="border border-[var(--border-default)] overflow-hidden p-0">
              {programs.length === 0 ? (
                <div className="text-center py-12 px-6">
                  <IconLayoutKanban className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
                  <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('strategy.no_linked_programs')}</h3>
                  <p className="text-[var(--text-tertiary)] mb-4">
                    {t('strategy.no_linked_programs_desc')}
                  </p>
                  <Link to={`/strategy/programs/new?portfolio_id=${id}`}>
                    <Button className="bg-[var(--accent-default)] hover:bg-[var(--accent-hover)]">
                      {t('strategy.create_new_program')}
                    </Button>
                  </Link>
                </div>
              ) : (
                <Table hoverable>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('strategy.program')}</TableHead>
                      <TableHead>{t('common.status')}</TableHead>
                      <TableHead>{t('common.priority')}</TableHead>
                      <TableHead>{t('strategy.completion')}</TableHead>
                      <TableHead>{t('strategy.projects')}</TableHead>
                      <TableHead>{t('strategy.program_manager')}</TableHead>
                      <TableHead className="w-20 text-center">{t('common.view')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {programs.map((program) => (
                      <TableRow key={program.id}>
                        <TableCell>
                          <div className="flex items-center gap-3">
                            <div className="h-9 w-9 rounded-lg bg-[var(--support-indigo-subtle)] flex items-center justify-center">
                              <IconLayoutKanban className="h-4 w-4 text-[var(--support-indigo-text)]" />
                            </div>
                            <div>
                              <Link
                                to={`/strategy/programs/${program.id}`}
                                className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] transition-colors"
                              >
                                {program.name}
                              </Link>
                              <p className="text-xs text-[var(--text-tertiary)]">{program.code}</p>
                            </div>
                          </div>
                        </TableCell>
                        <TableCell>
                          <StatusBadge type="project" status={program.status} size="sm" />
                        </TableCell>
                        <TableCell>
                          <StatusBadge type="priority" status={program.priority} size="sm" />
                        </TableCell>
                        <TableCell className="w-32">
                          <div className="space-y-1">
                            <Progress value={program.progress} size="sm" />
                            <span className="text-xs text-[var(--text-tertiary)]">
                              {Math.round(program.progress)}%
                            </span>
                          </div>
                        </TableCell>
                        <TableCell>
                          <span className="text-sm text-[var(--text-secondary)]">
                            {program.projects_count || 0}
                          </span>
                        </TableCell>
                        <TableCell>
                          {program.program_manager ? (
                            <div className="flex items-center gap-2">
                              <Avatar name={program.program_manager.name} size="sm" />
                              <span className="text-[var(--text-secondary)] text-sm">{program.program_manager.name}</span>
                            </div>
                          ) : (
                            <span className="text-[var(--text-tertiary)] text-sm">-</span>
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center justify-center">
                            <Link
                              to={`/strategy/programs/${program.id}`}
                              className="p-2 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] transition-colors"
                              title={t('common.view')}
                              aria-label={t('common.view')}
                            >
                              <IconEye className="h-4 w-4" />
                            </Link>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </Card>
          </div>
        </TabsContent>

        {/* Decisions Tab */}
        <TabsContent value="decisions">
          <DecisionsSection
            decidable_type="portfolio"
            decidable_id={portfolio.id}
            decidable_name={portfolio.name}
            permissions={{
              canView: canViewStrategy,
              canCreate: canCreateStrategy,
              canEdit: canEditStrategy,
            }}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DirectionView;
