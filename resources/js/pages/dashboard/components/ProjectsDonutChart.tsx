import React from 'react';
import { useTranslation } from 'react-i18next';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui';
import {IconChartPie} from '@tabler/icons-react';

interface ProjectsByStatus {
  draft: number;
  planning: number;
  in_progress: number;
  on_hold: number;
  completed: number;
  cancelled: number;
}

interface ProjectsDonutChartProps {
  data: ProjectsByStatus;
  loading?: boolean;
}

const statusConfig: Record<string, { key: string; color: string }> = {
  draft: { key: 'status.draft', color: 'var(--text-tertiary)' },
  planning: { key: 'status.planning', color: 'var(--accent-muted)' },
  in_progress: { key: 'status.in_progress', color: 'var(--accent-default)' },
  on_hold: { key: 'status.on_hold', color: 'var(--status-warning)' },
  completed: { key: 'status.completed', color: 'var(--status-success)' },
  cancelled: { key: 'status.cancelled', color: 'var(--status-danger)' },
};

export const ProjectsDonutChart: React.FC<ProjectsDonutChartProps> = ({ data, loading }) => {
  const { t } = useTranslation();
  const chartData = Object.entries(data)
    .filter(([_, value]) => value > 0)
    .map(([key, value]) => ({
      name: statusConfig[key] ? t(statusConfig[key].key) : key,
      value,
      color: statusConfig[key]?.color || 'var(--text-tertiary)',
    }));

  const total = Object.values(data).reduce((sum, val) => sum + val, 0);

  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconChartPie className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.projects_by_status')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-[280px] flex items-center justify-center">
            <div className="animate-pulse w-40 h-40 rounded-full bg-[var(--surface-muted)]" />
          </div>
        </CardContent>
      </Card>
    );
  }

  if (total === 0) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconChartPie className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.projects_by_status')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-[280px] flex flex-col items-center justify-center text-[var(--text-tertiary)]">
            <IconChartPie className="h-12 w-12 mb-2 opacity-50" />
            <p className="text-sm">{t('dashboard.no_projects')}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const CustomTooltip = ({ active, payload }: any) => {
    if (active && payload && payload.length) {
      const data = payload[0];
      const percentage = ((data.value / total) * 100).toFixed(1);
      return (
        <div className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg shadow-lg p-3">
          <p className="text-sm font-medium text-[var(--text-primary)]">{data.name}</p>
          <p className="text-sm text-[var(--text-secondary)]">
            {data.value} ({percentage}%)
          </p>
        </div>
      );
    }
    return null;
  };

  const renderLegend = () => (
    <div className="flex flex-wrap justify-center gap-3 mt-2">
      {chartData.map((entry) => (
        <div key={entry.name} className="flex items-center gap-1">
          <div
            className="w-3 h-3 rounded-full"
            style={{ backgroundColor: entry.color }}
          />
          <span className="text-xs text-[var(--text-secondary)]">
            {entry.name}: {entry.value}
          </span>
        </div>
      ))}
    </div>
  );

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <CardTitle className="flex items-center gap-2 text-sm">
          <IconChartPie className="h-4 w-4 text-[var(--accent-default)]" />
          {t('dashboard.projects_by_status')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4">
        <div className="h-[220px]" role="img" aria-label={t('dashboard.projects_by_status')}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={chartData}
                cx="50%"
                cy="50%"
                innerRadius={50}
                outerRadius={80}
                paddingAngle={2}
                dataKey="value"
              >
                {chartData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={entry.color} stroke="none" />
                ))}
              </Pie>
              <Tooltip content={<CustomTooltip />} />
            </PieChart>
          </ResponsiveContainer>
        </div>
        <div className="text-center -mt-4 mb-2">
          <p className="text-2xl font-bold text-[var(--text-primary)]">{total}</p>
          <p className="text-xs text-[var(--text-tertiary)]">{t('dashboard.total_projects')}</p>
        </div>
        {renderLegend()}
      </CardContent>
    </Card>
  );
};

export default ProjectsDonutChart;
