import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { dashboardApi } from '@shared/api/settings';
import { formatNumber } from '@shared/lib/utils';
import {
  Card,
  CardContent,
  Progress,
  Skeleton,
  Select,
  DatePicker,
  StatStrip,
  PageHeader,
  SectionHeader,
  StatusBadge,
} from '@shared/ui';
import {IconLayoutKanban, IconSquareCheck, IconTrendingUp, IconTarget, IconChartPie, IconArrowLeft, IconCalendar, IconBuilding, IconCurrencyDollar, IconAlertTriangle, IconClockHour4, IconActivity} from '@tabler/icons-react';

interface ProjectStats {
  total: number;
  active: number;
  completed: number;
  on_hold: number;
  cancelled: number;
  draft: number;
  planning: number;
}

interface TaskStats {
  total: number;
  completed: number;
  in_progress: number;
  pending: number;
  overdue: number;
}

// API يعيد object بشكل { status: count }
type ProjectsByStatusData = Record<string, number>;

interface RecentProject {
  id: number;
  code: string;
  name: string;
  status: string;
  progress: number;
  department: { name: string } | null;
  tasks_count?: number;
  completed_tasks_count?: number;
}

// الإحصائيات المتقدمة
interface BudgetSummary {
  total_budget: number;
  total_actual: number;
  variance: number;
  variance_percentage: number;
  over_budget_count: number;
}

interface DepartmentPerformance {
  id: number;
  name: string;
  total_projects: number;
  completed: number;
  active: number;
  overdue: number;
  completion_rate: number;
}

interface OverdueProjects {
  total: number;
  critical: number;
}

interface MonthlyTrend {
  month: string;
  month_name: string;
  projects_started: number;
  projects_completed: number;
  tasks_completed: number;
}

interface AdvancedStats {
  avg_completion_time: number | null;
  budget_summary: BudgetSummary;
  departments_performance: DepartmentPerformance[];
  overdue_projects: OverdueProjects;
  monthly_trends: MonthlyTrend[];
}

const statusLabelKeys: Record<string, string> = {
  draft: 'status.draft',
  planning: 'status.planning',
  in_progress: 'status.in_progress',
  on_hold: 'status.on_hold',
  completed: 'status.completed',
  cancelled: 'status.cancelled',
};

type DateFilterType = 'month' | 'quarter' | 'year' | 'custom' | 'all';

interface DateFilter {
  type: DateFilterType;
  startDate?: string;
  endDate?: string;
}

const dateFilterOptionKeys = [
  { value: 'all', labelKey: 'projects.stats_all_periods' },
  { value: 'month', labelKey: 'projects.stats_this_month' },
  { value: 'quarter', labelKey: 'projects.stats_quarter' },
  { value: 'year', labelKey: 'projects.stats_this_year' },
  { value: 'custom', labelKey: 'projects.stats_custom' },
];

const getDateRange = (filterType: DateFilterType): { startDate: string; endDate: string } | null => {
  const now = new Date();
  const endDate = now.toISOString().split('T')[0];

  switch (filterType) {
    case 'month': {
      const startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
      return { startDate, endDate };
    }
    case 'quarter': {
      const quarterStart = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
      return { startDate: quarterStart.toISOString().split('T')[0], endDate };
    }
    case 'year': {
      const startDate = new Date(now.getFullYear(), 0, 1).toISOString().split('T')[0];
      return { startDate, endDate };
    }
    default:
      return null;
  }
};

const ProjectStatistics: React.FC = () => {
  const { t } = useTranslation();
  const [loading, setLoading] = useState(true);
  const [projectStats, setProjectStats] = useState<ProjectStats | null>(null);
  const [taskStats, setTaskStats] = useState<TaskStats | null>(null);
  const [projectsByStatus, setProjectsByStatus] = useState<ProjectsByStatusData>({});
  const [recentProjects, setRecentProjects] = useState<RecentProject[]>([]);
  const [advancedStats, setAdvancedStats] = useState<AdvancedStats | null>(null);
  const [dateFilter, setDateFilter] = useState<DateFilter>({ type: 'all' });
  const [showCustomDatePicker, setShowCustomDatePicker] = useState(false);

  const handleDateFilterChange = (type: DateFilterType) => {
    if (type === 'custom') {
      setShowCustomDatePicker(true);
      setDateFilter({ type: 'custom' });
    } else {
      setShowCustomDatePicker(false);
      const dateRange = getDateRange(type);
      setDateFilter({ type, ...dateRange });
    }
  };

  const handleCustomDateChange = (field: 'startDate' | 'endDate', value: string) => {
    setDateFilter(prev => ({ ...prev, [field]: value }));
  };

  useEffect(() => {
    const fetchData = async () => {
      try {
        // تجهيز معاملات الفلتر الزمني
        const dateParams = dateFilter.startDate && dateFilter.endDate
          ? { start_date: dateFilter.startDate, end_date: dateFilter.endDate }
          : undefined;

        const [statsData, statusData, projectsData, advancedData] = await Promise.all([
          dashboardApi.getStats(dateParams),
          dashboardApi.getProjectsByStatus(dateParams),
          dashboardApi.getRecentProjects(),
          dashboardApi.getAdvancedStats(),
        ]);

        const stats = statsData as { projects: ProjectStats; tasks: TaskStats };
        setProjectStats(stats.projects);
        setTaskStats(stats.tasks);
        setProjectsByStatus(statusData as ProjectsByStatusData);
        setRecentProjects(projectsData as RecentProject[]);
        setAdvancedStats(advancedData as AdvancedStats);
      } catch (error) {
        console.error('Failed to fetch statistics:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [dateFilter]);

  if (loading) {
    return <StatisticsSkeleton />;
  }

  const completionRate = projectStats?.total
    ? Math.round((projectStats.completed / projectStats.total) * 100)
    : 0;

  const taskCompletionRate = taskStats?.total
    ? Math.round((taskStats.completed / taskStats.total) * 100)
    : 0;

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('projects.statistics')}
        description={t('projects.stats_overview')}
        icon={IconLayoutKanban}
        iconTone="project"
        actions={
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <div className="flex items-center gap-2">
              <IconCalendar className="h-4 w-4 text-[var(--text-tertiary)]" />
              <label htmlFor="project-statistics-date-filter" className="sr-only">
                {t('common.filter')}
              </label>
              <Select
                id="project-statistics-date-filter"
                value={dateFilter.type}
                onChange={(e) => handleDateFilterChange(e.target.value as DateFilterType)}
                options={dateFilterOptionKeys.map(o => ({ value: o.value, label: t(o.labelKey) }))}
                className="w-32 sm:w-40"
              />
            </div>
            {showCustomDatePicker && (
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                <div className="w-[8.5rem] shrink-0 sm:w-40">
                  <DatePicker
                    value={dateFilter.startDate || ''}
                    onChange={(value) => handleCustomDateChange('startDate', value)}
                    placeholder={t('common.from')}
                    aria-label={t('common.from')}
                    title={t('common.from')}
                    className="min-h-9 px-2 py-1"
                  />
                </div>
                <span className="text-[var(--text-tertiary)]">{t('common.to')}</span>
                <div className="w-[8.5rem] shrink-0 sm:w-40">
                  <DatePicker
                    value={dateFilter.endDate || ''}
                    onChange={(value) => handleCustomDateChange('endDate', value)}
                    placeholder={t('common.to')}
                    aria-label={t('common.to')}
                    title={t('common.to')}
                    className="min-h-9 px-2 py-1"
                  />
                </div>
              </div>
            )}
          </div>
        }
      />

      {/* Stats Cards */}
      <StatStrip
        items={[
          { label: t('projects.stats_total_projects'), value: projectStats?.total || 0, tone: 'neutral' },
          { label: t('status.in_progress'), value: projectStats?.active || 0, tone: 'accent' },
          { label: t('status.completed'), value: projectStats?.completed || 0, tone: 'success' },
          { label: t('status.on_hold'), value: projectStats?.on_hold || 0, tone: 'warning' },
        ]}
      />

      {/* Completion Rates */}
      <div className="grid gap-2 sm:gap-4 lg:grid-cols-2">
        {/* Project Completion */}
        <Card>
          <CardContent className="p-3 sm:p-4">
            <SectionHeader
              title={t('projects.stats_completion_rate')}
              level={3}
              size="compact"
              icon={IconTrendingUp}
              iconTone="project"
              className="mb-3"
              actions={
                <span className="text-lg font-bold text-[var(--support-indigo-text)]">{completionRate}%</span>
              }
            />
            <Progress value={completionRate} size="sm" showValue={false} />
            <div className="mt-3 flex items-center justify-center gap-4 text-center">
              <div className="flex items-center gap-1">
                <span className="text-base font-bold text-[var(--status-success-text)]">{projectStats?.completed || 0}</span>
                <span className="text-xs text-[var(--status-success-text)]">{t('status.completed')}</span>
              </div>
              <span className="text-[var(--border-default)]">|</span>
              <div className="flex items-center gap-1">
                <span className="text-base font-bold text-[var(--text-primary)]">{projectStats?.total || 0}</span>
                <span className="text-xs text-[var(--text-tertiary)]">{t('common.total')}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Task Completion */}
        <Card>
          <CardContent className="p-3 sm:p-4">
            <SectionHeader
              title={t('projects.stats_task_completion_rate')}
              level={3}
              size="compact"
              icon={IconSquareCheck}
              iconTone="task"
              className="mb-3"
              actions={
                <span className="text-lg font-bold text-[var(--support-teal-text)]">{taskCompletionRate}%</span>
              }
            />
            <Progress value={taskCompletionRate} size="sm" showValue={false} />
            <div className="mt-3 flex items-center justify-center gap-3 text-center">
              <div className="flex items-center gap-1">
                <span className="text-sm font-bold text-[var(--status-success-text)]">{taskStats?.completed || 0}</span>
                <span className="text-xs text-[var(--status-success-text)]">{t('status.completed')}</span>
              </div>
              <span className="text-[var(--border-default)]">|</span>
              <div className="flex items-center gap-1">
                <span className="text-sm font-bold text-[var(--support-teal-text)]">{taskStats?.in_progress || 0}</span>
                <span className="text-xs text-[var(--support-teal-text)]">{t('status.in_progress')}</span>
              </div>
              <span className="text-[var(--border-default)]">|</span>
              <div className="flex items-center gap-1">
                <span className="text-sm font-bold text-[var(--status-danger-text)]">{taskStats?.overdue || 0}</span>
                <span className="text-xs text-[var(--status-danger-text)]">{t('status.delayed')}</span>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Projects by Status */}
      <Card>
        <CardContent className="p-3 sm:p-4">
          <SectionHeader
            title={t('projects.stats_by_status')}
            level={3}
            size="compact"
            icon={IconChartPie}
            iconTone="project"
            className="mb-3"
          />
          <div className="grid grid-cols-3 sm:grid-cols-6 gap-2">
            {Object.entries(statusLabelKeys).map(([status, labelKey]) => {
              const count = projectsByStatus[status] || 0;

              return (
                <Link
                  key={status}
                  to={`/projects?status=${status}`}
                  className="flex flex-col items-center gap-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-2 text-center transition-colors hover:border-[var(--support-indigo-default)] hover:bg-[var(--support-indigo-subtle)] hover:shadow-sm sm:p-3"
                  aria-label={`${t(labelKey)}: ${count}`}
                >
                  <p className="text-lg font-bold text-[var(--text-primary)] sm:text-xl">{count}</p>
                  <StatusBadge type="project" status={status} size="sm" />
                </Link>
              );
            })}
          </div>
        </CardContent>
      </Card>

      {/* إحصائيات متقدمة */}
      {advancedStats && (
        <>
          {/* بطاقات الإحصائيات المتقدمة */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4">
            {/* متوسط وقت الإنجاز */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-[var(--text-tertiary)] truncate">{t('projects.stats_avg_completion_time')}</p>
                    <p className="text-lg sm:text-2xl font-bold text-[var(--text-primary)] mt-0 sm:mt-1">
                      {advancedStats.avg_completion_time
                        ? t('projects.stats_days_count', { count: advancedStats.avg_completion_time })
                        : t('projects.stats_not_available')}
                    </p>
                  </div>
                  <div className="h-8 w-8 sm:h-10 sm:w-10 rounded-lg bg-[var(--support-indigo-subtle)] flex items-center justify-center shrink-0">
                    <IconClockHour4 className="h-4 w-4 sm:h-5 sm:w-5 text-[var(--support-indigo-text)]" />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* المشاريع المتأخرة */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-[var(--text-tertiary)] truncate">{t('projects.stats_overdue_projects')}</p>
                    <p className="text-lg sm:text-2xl font-bold text-[var(--status-danger-text)] mt-0 sm:mt-1">
                      {advancedStats.overdue_projects.total}
                    </p>
                    {advancedStats.overdue_projects.critical > 0 && (
                      <p className="text-xs text-[var(--status-danger-text)] mt-0">
                        {t('projects.stats_critical_count', { count: advancedStats.overdue_projects.critical })}
                      </p>
                    )}
                  </div>
                  <div className="h-8 w-8 sm:h-10 sm:w-10 rounded-lg bg-[var(--status-danger-subtle)] flex items-center justify-center shrink-0">
                    <IconAlertTriangle className="h-4 w-4 sm:h-5 sm:w-5 text-[var(--status-danger-text)]" />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* إجمالي الميزانية */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-[var(--text-tertiary)] truncate">{t('projects.stats_total_budget')}</p>
                    <p className="text-lg sm:text-2xl font-bold text-[var(--text-primary)] mt-0 sm:mt-1">
                      {formatNumber(advancedStats.budget_summary.total_budget)}
                    </p>
                    <p className="text-xs text-[var(--text-tertiary)] mt-0">{t('projects.stats_currency')}</p>
                  </div>
                  <div className="h-8 w-8 sm:h-10 sm:w-10 rounded-lg bg-[var(--support-indigo-subtle)] flex items-center justify-center shrink-0">
                    <IconCurrencyDollar className="h-4 w-4 sm:h-5 sm:w-5 text-[var(--support-indigo-text)]" />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* فرق الميزانية */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-[var(--text-tertiary)] truncate">{t('projects.stats_budget_variance')}</p>
                    <p className={`text-lg sm:text-2xl font-bold mt-0 sm:mt-1 ${
                      advancedStats.budget_summary.variance >= 0 ? 'text-[var(--status-success-text)]' : 'text-[var(--status-danger-text)]'
                    }`}>
                      {advancedStats.budget_summary.variance >= 0 ? '+' : ''}
                      {advancedStats.budget_summary.variance_percentage}%
                    </p>
                    {advancedStats.budget_summary.over_budget_count > 0 && (
                      <p className="text-xs text-[var(--status-warning-text)] mt-0">
                        {t('projects.stats_over_budget', { count: advancedStats.budget_summary.over_budget_count })}
                      </p>
                    )}
                  </div>
                  <div className={`h-8 w-8 sm:h-10 sm:w-10 rounded-lg ${
                    advancedStats.budget_summary.variance >= 0 ? 'bg-[var(--status-success-subtle)]' : 'bg-[var(--status-danger-subtle)]'
                  } flex items-center justify-center shrink-0`}>
                    <IconActivity className={`h-4 w-4 sm:h-5 sm:w-5 ${
                      advancedStats.budget_summary.variance >= 0 ? 'text-[var(--status-success-text)]' : 'text-[var(--status-danger-text)]'
                    }`} />
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* الاتجاهات الشهرية وأداء الأقسام */}
          <div className="grid gap-2 sm:gap-4 lg:grid-cols-2">
            {/* الاتجاهات الشهرية */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <SectionHeader
                  title={t('projects.stats_monthly_trends')}
                  level={3}
                  size="compact"
                  icon={IconTrendingUp}
                  iconTone="project"
                  className="mb-3"
                />
                <div className="space-y-2">
                  {advancedStats.monthly_trends.map((trend) => (
                    <div
                      key={trend.month}
                      className="flex items-center gap-3 p-2 rounded-lg bg-[var(--bg-secondary)] hover:bg-[var(--bg-tertiary)] transition-colors"
                    >
                      <span className="text-xs font-medium text-[var(--text-secondary)] w-16 shrink-0">
                        {trend.month_name}
                      </span>
                      <div className="flex-1 flex items-center gap-4">
                        <div className="flex items-center gap-1">
                          <span className="text-sm font-bold text-[var(--support-indigo-text)]">{trend.projects_started}</span>
                          <span className="text-xs text-[var(--text-tertiary)]">{t('projects.stats_started')}</span>
                        </div>
                        <div className="flex items-center gap-1">
                          <span className="text-sm font-bold text-[var(--status-success-text)]">{trend.projects_completed}</span>
                          <span className="text-xs text-[var(--text-tertiary)]">{t('projects.stats_finished')}</span>
                        </div>
                        <div className="flex items-center gap-1">
                          <span className="text-sm font-bold text-[var(--support-teal-text)]">{trend.tasks_completed}</span>
                          <span className="text-xs text-[var(--text-tertiary)]">{t('projects.stats_task')}</span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            {/* أداء الأقسام */}
            <Card>
              <CardContent className="p-3 sm:p-4">
                <SectionHeader
                  title={t('projects.stats_dept_performance')}
                  level={3}
                  size="compact"
                  icon={IconBuilding}
                  iconTone="project"
                  className="mb-3"
                />
                {advancedStats.departments_performance.length === 0 ? (
                  <div className="text-center py-6">
                    <IconBuilding className="h-10 w-10 text-[var(--text-tertiary)] mx-auto mb-2" />
                    <p className="text-sm text-[var(--text-tertiary)]">{t('common.no_data')}</p>
                  </div>
                ) : (
                  <div className="space-y-2">
                    {advancedStats.departments_performance.map((dept) => (
                      <div
                        key={dept.id}
                        className="p-2 rounded-lg border border-[var(--border-default)] hover:border-[var(--support-indigo-default)] transition-colors"
                      >
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-sm font-medium text-[var(--text-primary)] truncate">
                            {dept.name}
                          </span>
                          <span className={`text-sm font-bold ${
                            dept.completion_rate >= 70
                              ? 'text-[var(--status-success-text)]'
                              : dept.completion_rate >= 40
                              ? 'text-[var(--status-warning-text)]'
                              : 'text-[var(--status-danger-text)]'
                          }`}>
                            {dept.completion_rate}%
                          </span>
                        </div>
                        <Progress
                          value={dept.completion_rate}
                          size="sm"
                          showValue={false}
                        />
                        <div className="flex items-center gap-3 mt-1 text-xs">
                          <span className="text-[var(--text-tertiary)]">
                            {t('common.total')}: <span className="font-medium text-[var(--text-secondary)]">{dept.total_projects}</span>
                          </span>
                          <span className="text-[var(--status-success-text)]">
                            {t('status.completed')}: <span className="font-medium">{dept.completed}</span>
                          </span>
                          {dept.overdue > 0 && (
                            <span className="text-[var(--status-danger-text)]">
                              {t('status.delayed')}: <span className="font-medium">{dept.overdue}</span>
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </>
      )}

      {/* Recent Projects */}
      <Card>
        <CardContent className="p-3 sm:p-4">
          <SectionHeader
            title={t('projects.stats_recent_projects')}
            level={3}
            size="compact"
            icon={IconLayoutKanban}
            iconTone="project"
            className="mb-3"
            actions={
              <Link
                to="/projects"
                className="flex items-center gap-1 text-xs font-medium text-[var(--accent-default)] transition-colors hover:text-[var(--accent-hover)]"
              >
                {t('common.view_all')}
                <IconArrowLeft className="h-3 w-3 rtl:rotate-180" />
              </Link>
            }
          />
          {recentProjects.length === 0 ? (
            <div className="text-center py-8">
              <IconLayoutKanban className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-3" />
              <p className="text-sm text-[var(--text-tertiary)]">{t('projects.no_projects')}</p>
            </div>
          ) : (
            <div className="space-y-2">
              {recentProjects.map((project) => (
                <Link
                  key={project.id}
                  to={`/projects/${project.id}`}
                  className="flex items-center gap-3 p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--support-indigo-default)] hover:bg-[var(--support-indigo-subtle)] transition-colors"
                >
                  <IconTarget className="h-4 w-4 text-[var(--support-indigo-text)] shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <h4 className="font-medium text-[var(--text-primary)] text-sm truncate">{project.name}</h4>
                      <span className="text-xs text-[var(--text-tertiary)]">{project.code}</span>
                    </div>
                    <div className="flex items-center gap-2 mt-1">
                      <div className="flex-1 max-w-[120px]">
                        <Progress value={project.progress} size="sm" showValue={false} />
                      </div>
                      <span className="text-xs font-medium text-[var(--text-secondary)]">{project.progress}%</span>
                    </div>
                  </div>
                  <StatusBadge type="project" status={project.status} size="sm" className="shrink-0" />
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

const StatisticsSkeleton: React.FC = () => (
  <div className="space-y-4 sm:space-y-6">
    {/* Header Skeleton */}
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-3">
        <Skeleton variant="rounded" width={40} height={40} className="rounded-lg" />
        <div className="space-y-2">
          <Skeleton width={150} height={20} />
          <Skeleton width={200} height={14} />
        </div>
      </div>
      <Skeleton width={160} height={36} className="rounded-lg" />
    </div>
    {/* Stats Cards Skeleton */}
    <div className="grid gap-2 sm:gap-4 grid-cols-2 md:grid-cols-4">
      {[1, 2, 3, 4].map((i) => (
        <Card key={i}>
          <CardContent className="p-3 sm:p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-2">
                <Skeleton width={80} height={12} />
                <Skeleton width={50} height={24} />
              </div>
              <Skeleton variant="rounded" width={40} height={40} className="rounded-lg" />
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
    {/* Completion Rates Skeleton */}
    <div className="grid gap-2 sm:gap-4 lg:grid-cols-2">
      {[1, 2].map((i) => (
        <Card key={i}>
          <CardContent className="p-3 sm:p-4">
            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Skeleton variant="rounded" width={32} height={32} className="rounded-lg" />
                <Skeleton width={120} height={16} />
                <Skeleton width={40} height={20} className="ms-auto" />
              </div>
              <Skeleton height={8} className="rounded-full" />
              <div className="flex justify-center gap-4">
                <Skeleton width={60} height={14} />
                <Skeleton width={60} height={14} />
              </div>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
    {/* Status Distribution Skeleton */}
    <Card>
      <CardContent className="p-3 sm:p-4">
        <div className="flex items-center gap-2 mb-3">
          <Skeleton variant="rounded" width={32} height={32} className="rounded-lg" />
          <Skeleton width={180} height={16} />
        </div>
        <div className="grid grid-cols-3 sm:grid-cols-6 gap-2">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <Skeleton key={i} height={60} className="rounded-lg" />
          ))}
        </div>
      </CardContent>
    </Card>
  </div>
);

export default ProjectStatistics;
