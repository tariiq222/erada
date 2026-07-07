import React, { useEffect, useState, useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { dashboardApi } from '@shared/api/settings';
import { formatDate, formatNumber, formatTime } from '@shared/lib/utils';
import { useAuth } from '@shared/contexts/AuthContext';
import {
  Card,
  CardHeader,
  CardTitle,
  CardContent,
  Progress,
  Skeleton,
  StatStrip,
  StatusBadge,
  type StatStripItem,
  type ProjectStatus,
} from '@shared/ui';
import {IconLayoutKanban, IconSquareCheck, IconAlertTriangle, IconClock, IconArrowLeft, IconTarget, IconRefresh} from '@tabler/icons-react';
import {
  DateRangeFilter,
  ProjectsDonutChart,
  MonthlyTrendsChart,
  BudgetSummaryCard,
  DepartmentsPerformance,
  UpcomingTasksCard,
  OVRStatsCard,
  type DateRange,
} from './components';

interface Stats {
  projects: {
    total: number;
    active: number;
    completed: number;
    on_hold: number;
    draft: number;
    planning: number;
    cancelled: number;
  };
  tasks: {
    total: number;
    pending: number;
    in_progress: number;
    completed: number;
    overdue: number;
  };
  users: number | null;
}

interface AdvancedStats {
  avg_completion_time: number | null;
  budget_summary: {
    total_budget: number;
    total_actual: number;
    variance: number;
    variance_percentage: number;
    over_budget_count: number;
  };
  departments_performance: Array<{
    id: number;
    name: string;
    total_projects: number;
    completed: number;
    active: number;
    overdue: number;
    completion_rate: number;
  }>;
  overdue_projects: {
    total: number;
    critical: number;
  };
  monthly_trends: Array<{
    month: string;
    month_name: string;
    projects_started: number;
    projects_completed: number;
    tasks_completed: number;
  }>;
}

interface ProjectsByStatus {
  draft: number;
  planning: number;
  in_progress: number;
  on_hold: number;
  completed: number;
  cancelled: number;
}

interface Project {
  id: number;
  code: string;
  name: string;
  status: string;
  progress: number;
  department: { name: string } | null;
}

interface Task {
  id: number;
  title: string;
  due_date: string;
  status: string;
  priority: string;
  project: { id: number; name: string; code: string } | null;
  assignee: { name: string } | null;
}

const Dashboard: React.FC = () => {
  const { t } = useTranslation();
  const { user, isSuperAdmin } = useAuth();
  const [stats, setStats] = useState<Stats | null>(null);
  const [advancedStats, setAdvancedStats] = useState<AdvancedStats | null>(null);
  const [projectsByStatus, setProjectsByStatus] = useState<ProjectsByStatus | null>(null);
  const [recentProjects, setRecentProjects] = useState<Project[]>([]);
  const [overdueTasks, setOverdueTasks] = useState<Task[]>([]);
  const [upcomingTasks, setUpcomingTasks] = useState<Task[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

  // Date filter state
  const [dateRange, setDateRange] = useState<DateRange>('last30');
  const [startDate, setStartDate] = useState<string>(() => {
    const date = new Date();
    date.setDate(date.getDate() - 30);
    return date.toISOString().split('T')[0];
  });
  const [endDate, setEndDate] = useState<string>(() => {
    return new Date().toISOString().split('T')[0];
  });

  const fetchData = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    try {
      const dateParams = { start_date: startDate, end_date: endDate };

      const [
        statsData,
        advancedStatsData,
        projectsByStatusData,
        projectsData,
        overdueTasksData,
        upcomingTasksData,
      ] = await Promise.all([
        dashboardApi.getStats(dateParams),
        dashboardApi.getAdvancedStats(),
        dashboardApi.getProjectsByStatus(dateParams),
        dashboardApi.getRecentProjects(),
        dashboardApi.getOverdueTasks(),
        dashboardApi.getMyUpcomingTasks(),
      ]);

      setStats(statsData as Stats);
      setAdvancedStats(advancedStatsData as AdvancedStats);
      setProjectsByStatus(projectsByStatusData as ProjectsByStatus);
      setRecentProjects(projectsData as Project[]);
      setOverdueTasks(overdueTasksData as Task[]);
      setUpcomingTasks(upcomingTasksData as Task[]);
      setLastUpdated(new Date());
    } catch (error) {
      console.error('Failed to fetch dashboard data:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [startDate, endDate]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleDateRangeChange = (range: DateRange, start?: string, end?: string) => {
    setDateRange(range);
    if (start && end) {
      setStartDate(start);
      setEndDate(end);
    }
  };

  const handleRefresh = () => {
    fetchData(true);
  };

  const overviewItems: StatStripItem[] = useMemo(() => [
    {
      label: t('dashboard.total_projects'),
      value: formatNumber(stats?.projects.total || 0),
      tone: 'neutral',
    },
    {
      label: t('dashboard.active_projects'),
      value: formatNumber(stats?.projects.active || 0),
      tone: 'accent',
    },
    {
      label: t('dashboard.completed_projects'),
      value: formatNumber(stats?.projects.completed || 0),
      tone: 'success',
    },
    {
      label: t('dashboard.total_tasks'),
      value: formatNumber(stats?.tasks.total || 0),
      tone: 'neutral',
    },
    {
      label: t('dashboard.overdue_tasks'),
      value: formatNumber(stats?.tasks.overdue || 0),
      tone: 'danger',
    },
    isSuperAdmin() && stats?.users
      ? {
          label: t('dashboard.users_count'),
          value: formatNumber(stats.users),
          tone: 'accent' as const,
        }
      : {
          label: t('dashboard.avg_completion'),
          value: `${formatNumber(advancedStats?.avg_completion_time || 0)} ${t('common.day')}`,
          tone: 'neutral' as const,
        },
  ], [t, stats, advancedStats, isSuperAdmin]);

  if (loading) {
    return <DashboardSkeleton />;
  }

  return (
    <div className="space-y-10">
      {/* Welcome Header with Filters */}
      <div className="p-4 bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-[var(--text-primary)]">
              {t('dashboard.welcome', { name: user?.name })}
            </h1>
            <p className="text-sm text-[var(--text-tertiary)] mt-1">
              {t('dashboard.summary')}
              {lastUpdated && (
                <span className="ms-2 text-xs">
                  • {t('common.last_update')}: {formatTime(lastUpdated)}
                </span>
              )}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <DateRangeFilter
              value={dateRange}
              onChange={handleDateRangeChange}
              customStartDate={startDate}
              customEndDate={endDate}
            />
            <button
              onClick={handleRefresh}
              disabled={refreshing}
              className="p-2 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors disabled:opacity-50"
              title={t('common.refresh_data')}
              aria-label={t('common.refresh_data')}
            >
              <IconRefresh className={`h-4 w-4 text-[var(--text-tertiary)] ${refreshing ? 'animate-spin' : ''}`} />
            </button>
          </div>
        </div>
      </div>

      {/* Overview */}
      <section className="space-y-4">
        <SectionLabel>{t('dashboard.section_overview')}</SectionLabel>
        <StatStrip items={overviewItems} />
      </section>

      {/* Analytics */}
      <section className="space-y-5">
        <SectionLabel>{t('dashboard.section_analytics')}</SectionLabel>
        <div className="grid gap-5 lg:grid-cols-2">
        <ProjectsDonutChart
          data={projectsByStatus || { draft: 0, planning: 0, in_progress: 0, on_hold: 0, completed: 0, cancelled: 0 }}
          loading={refreshing}
        />
        <MonthlyTrendsChart
          data={advancedStats?.monthly_trends || []}
          loading={refreshing}
        />
        </div>

        <div className="grid gap-5 lg:grid-cols-2">
        <BudgetSummaryCard
          data={advancedStats?.budget_summary || null}
          loading={refreshing}
        />
        <DepartmentsPerformance
          data={advancedStats?.departments_performance || []}
          loading={refreshing}
        />
        </div>
      </section>

      {/* Operations */}
      <section className="space-y-5">
        <SectionLabel>{t('dashboard.section_operations')}</SectionLabel>

        {/* Tasks Row */}
        <div className="grid gap-5 lg:grid-cols-2">
          <UpcomingTasksCard
            data={upcomingTasks}
            loading={refreshing}
          />

        {/* Overdue Tasks */}
        <Card>
          <CardHeader className="border-b border-[var(--border-default)] pb-3">
            <div className="flex items-center justify-between">
              <CardTitle className="flex items-center gap-2 text-sm">
                <IconAlertTriangle className="h-4 w-4 text-[var(--status-danger)]" />
                {t('dashboard.overdue_tasks_title')}
              </CardTitle>
              <Link
                to="/tasks?overdue=true"
                className="text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)] flex items-center gap-1"
              >
                {t('common.view_all')}
                <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
              </Link>
            </div>
          </CardHeader>
          <CardContent className="p-4">
            {overdueTasks.length === 0 ? (
              <div className="text-center py-8">
                <IconSquareCheck className="h-12 w-12 text-[var(--status-success)] mx-auto mb-3" />
                <p className="text-[var(--text-tertiary)] text-sm">{t('dashboard.no_overdue_tasks')}</p>
                <p className="text-xs text-[var(--text-tertiary)] mt-1">{t('dashboard.all_on_time')}</p>
              </div>
            ) : (
              <div className="space-y-2">
                {overdueTasks.slice(0, 5).map((task) => (
                  <Link
                    key={task.id}
                    to={`/tasks/${task.id}`}
                    className="block p-3 rounded-lg border border-[var(--status-danger)] bg-[var(--status-danger-subtle)] hover:brightness-95 transition-[filter]"
                  >
                    <h4 className="font-medium text-[var(--text-primary)] mb-1 text-sm truncate">
                      {task.title}
                    </h4>
                    <div className="flex flex-wrap items-center gap-3 text-xs text-[var(--text-tertiary)]">
                      <span className="flex items-center gap-1 text-[var(--status-danger)]">
                        <IconClock className="h-3 w-3" />
                        {formatDate(task.due_date)}
                      </span>
                      {task.project && (
                        <span className="px-2 py-0 bg-[var(--surface-base)] rounded border border-[var(--border-default)]">
                          {task.project.code}
                        </span>
                      )}
                      {task.assignee && <span>{task.assignee.name}</span>}
                    </div>
                  </Link>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
        </div>

        {/* OVR – full width */}
        <OVRStatsCard />

        {/* Recent Projects */}
        <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-sm">
              <IconLayoutKanban className="h-4 w-4 text-[var(--accent-default)]" />
              {t('dashboard.recent_projects')}
            </CardTitle>
            <Link
              to="/projects"
              className="text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)] flex items-center gap-1"
            >
              {t('common.view_all')}
              <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
            </Link>
          </div>
        </CardHeader>
        <CardContent className="p-4">
          {recentProjects.length === 0 ? (
            <div className="text-center py-8">
              <IconLayoutKanban className="h-12 w-12 text-[var(--border-default)] mx-auto mb-3" />
              <p className="text-[var(--text-tertiary)] text-sm">{t('dashboard.no_projects')}</p>
            </div>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {recentProjects.map((project) => (
                <Link
                  key={project.id}
                  to={`/projects/${project.id}`}
                  className="block p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors"
                >
                  <div className="flex items-start justify-between gap-2 mb-2">
                    <div className="flex items-center gap-2 min-w-0">
                      <IconTarget className="h-4 w-4 text-[var(--accent-default)] shrink-0" />
                      <div className="min-w-0">
                        <h4 className="font-medium text-[var(--text-primary)] text-sm truncate">
                          {project.name}
                        </h4>
                        <p className="text-xs text-[var(--text-tertiary)]">{project.code}</p>
                      </div>
                    </div>
                  </div>
                  <StatusBadge
                    type="project"
                    status={project.status as ProjectStatus}
                    size="sm"
                    className="mb-2"
                  />
                  <Progress value={project.progress} size="sm" showValue />
                </Link>
              ))}
            </div>
          )}
        </CardContent>
        </Card>
      </section>

      {/* Overdue Projects Alert */}
      {advancedStats?.overdue_projects && advancedStats.overdue_projects.total > 0 && (
        <div className="p-4 rounded-lg bg-[var(--status-danger-subtle)] border border-[var(--status-danger)]">
          <div className="flex items-center gap-3">
            <IconAlertTriangle className="h-6 w-6 text-[var(--status-danger)]" />
            <div>
              <h3 className="font-medium text-[var(--text-primary)]">
                {t('dashboard.overdue_projects', { count: advancedStats.overdue_projects.total })}
              </h3>
              {advancedStats.overdue_projects.critical > 0 && (
                <p className="text-sm text-[var(--status-danger)]">
                  {t('dashboard.critical_overdue', { count: advancedStats.overdue_projects.critical })}
                </p>
              )}
            </div>
            <Link
              to="/projects?status=overdue"
              className="ms-auto text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)]"
            >
              {t('dashboard.view_overdue_projects')}
            </Link>
          </div>
        </div>
      )}
    </div>
  );
};

const SectionLabel: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <h2 className="px-1 text-xs font-semibold tracking-wide text-[var(--text-tertiary)]">
    {children}
  </h2>
);

const DashboardSkeleton: React.FC = () => (
  <div className="space-y-6">
    <Skeleton className="h-24 rounded-lg" />
    <div className="grid gap-4 grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
      {[1, 2, 3, 4, 5, 6].map((i) => (
        <Card key={i}>
          <CardContent className="p-3">
            <div className="flex items-center gap-3">
              <Skeleton variant="rounded" width={40} height={40} className="rounded-lg" />
              <div className="space-y-2 flex-1">
                <Skeleton width="80%" height={12} />
                <Skeleton width="50%" height={20} />
              </div>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
    <div className="grid gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader className="border-b border-[var(--border-default)]">
          <Skeleton width={180} height={24} />
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-[280px] flex items-center justify-center">
            <Skeleton variant="rounded" width={160} height={160} className="rounded-full" />
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader className="border-b border-[var(--border-default)]">
          <Skeleton width={180} height={24} />
        </CardHeader>
        <CardContent className="p-4">
          <Skeleton className="h-[280px] rounded" />
        </CardContent>
      </Card>
    </div>
    <div className="grid gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader className="border-b border-[var(--border-default)]">
          <Skeleton width={150} height={24} />
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-4">
            <Skeleton height={16} />
            <Skeleton height={32} />
            <Skeleton height={16} width="60%" />
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader className="border-b border-[var(--border-default)]">
          <Skeleton width={150} height={24} />
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-3">
            {[1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} height={60} className="rounded-lg" />
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
);

export default Dashboard;
