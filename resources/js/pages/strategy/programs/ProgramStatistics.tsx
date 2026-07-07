import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconChartBar} from '@tabler/icons-react';
import { strategyDashboardApi } from '@entities/strategy';
import { Card, Progress, Skeleton } from '@shared/ui';
import { PageHeader, StatStrip } from '@shared/ui';

interface DashboardSummary {
  portfolios: { total: number; active: number; avg_progress: number };
  programs: { total: number; active: number; avg_progress: number };
  projects: { total: number; unlinked: number };
  blockers: { open: number; critical: number; overdue: number };
  decisions: { pending: number };
}

const ProgramStatistics: React.FC = () => {
  const { t } = useTranslation();
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const data = await strategyDashboardApi.getSummary();
        setSummary(data as DashboardSummary);
      } catch (error) {
        console.error('Failed to fetch program statistics:', error);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, []);

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="space-y-2">
          <Skeleton className="h-7 w-64" variant="rounded" />
          <Skeleton className="h-4 w-80" variant="rounded" />
        </div>
        <Skeleton className="h-20 w-full" variant="rounded" />
        <Card>
          <Skeleton className="mb-3 h-5 w-40" variant="rounded" />
          <Skeleton className="h-3 w-full" variant="rounded" />
        </Card>
      </div>
    );
  }

  const programs = summary?.programs ?? { total: 0, active: 0, avg_progress: 0 };
  const projects = summary?.projects ?? { total: 0, unlinked: 0 };

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('strategy.programs.statisticsTitle')}
        subtitle={t('strategy.programs_subtitle')}
        icon={IconChartBar}
        iconTone="project"
      />

      <StatStrip
        items={[
          { label: t('strategy.programs.totalPrograms'), value: programs.total },
          { label: t('status.active'), value: programs.active, tone: 'accent' },
          { label: t('strategy.dashboard.avgProgress'), value: `${Math.round(programs.avg_progress)}%` },
          { label: t('projects.projects'), value: projects.total },
        ]}
      />

      <Card>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-[length:var(--text-small)] font-semibold text-[var(--text-primary)]">
            {t('strategy.programs.programProgress')}
          </h2>
          <span className="text-[length:var(--text-body)] font-bold tabular-nums text-[var(--text-primary)]">
            {Math.round(programs.avg_progress)}%
          </span>
        </div>
        <Progress value={programs.avg_progress} size="sm" />
      </Card>
    </div>
  );
};

export default ProgramStatistics;
