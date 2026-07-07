import React from 'react';
import { useTranslation } from 'react-i18next';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui';
import {IconTrendingUp} from '@tabler/icons-react';

interface MonthlyTrend {
  month: string;
  month_name: string;
  projects_started: number;
  projects_completed: number;
  tasks_completed: number;
}

interface MonthlyTrendsChartProps {
  data: MonthlyTrend[];
  loading?: boolean;
}

export const MonthlyTrendsChart: React.FC<MonthlyTrendsChartProps> = ({ data, loading }) => {
  const { t } = useTranslation();
  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconTrendingUp className="h-4 w-4 text-[var(--status-success)]" />
            {t('dashboard.monthly_trends')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-[280px] flex items-center justify-center">
            <div className="animate-pulse w-full h-40 bg-[var(--surface-muted)] rounded" />
          </div>
        </CardContent>
      </Card>
    );
  }

  if (!data || data.length === 0) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconTrendingUp className="h-4 w-4 text-[var(--status-success)]" />
            {t('dashboard.monthly_trends')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-[280px] flex flex-col items-center justify-center text-[var(--text-tertiary)]">
            <IconTrendingUp className="h-12 w-12 mb-2 opacity-50" />
            <p className="text-sm">{t('common.no_data')}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg shadow-lg p-3">
          <p className="text-sm font-medium text-[var(--text-primary)] mb-2">{label}</p>
          {payload.map((entry: any, index: number) => (
            <div key={index} className="flex items-center gap-2 text-sm">
              <div
                className="w-2.5 h-2.5 rounded-full"
                style={{ backgroundColor: entry.color }}
              />
              <span className="text-[var(--text-secondary)]">{entry.name}:</span>
              <span className="font-medium text-[var(--text-primary)]">{entry.value}</span>
            </div>
          ))}
        </div>
      );
    }
    return null;
  };

  const chartData = data.map((item) => ({
    ...item,
    name: item.month_name,
  }));

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <CardTitle className="flex items-center gap-2 text-sm">
          <IconTrendingUp className="h-4 w-4 text-[var(--status-success)]" />
          {t('dashboard.monthly_trends')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4">
        <div className="h-[280px]" role="img" aria-label={t('dashboard.monthly_trends')}>
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
              <CartesianGrid
                strokeDasharray="3 3"
                stroke="var(--border-default)"
                vertical={false}
              />
              <XAxis
                dataKey="name"
                tick={{ fontSize: 12, fill: 'var(--text-tertiary)' }}
                axisLine={{ stroke: 'var(--border-default)' }}
                tickLine={false}
              />
              <YAxis
                tick={{ fontSize: 12, fill: 'var(--text-tertiary)' }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip content={<CustomTooltip />} />
              <Legend
                wrapperStyle={{ paddingTop: '10px' }}
                formatter={(value) => (
                  <span className="text-xs text-[var(--text-secondary)]">{value}</span>
                )}
              />
              <Line
                type="monotone"
                dataKey="projects_started"
                name={t('dashboard.projects_started')}
                stroke="var(--accent-default)"
                strokeWidth={2}
                dot={{ fill: 'var(--accent-default)', strokeWidth: 0, r: 4 }}
                activeDot={{ r: 6, strokeWidth: 0 }}
              />
              <Line
                type="monotone"
                dataKey="projects_completed"
                name={t('dashboard.projects_completed')}
                stroke="var(--status-success)"
                strokeWidth={2}
                dot={{ fill: 'var(--status-success)', strokeWidth: 0, r: 4 }}
                activeDot={{ r: 6, strokeWidth: 0 }}
              />
              <Line
                type="monotone"
                dataKey="tasks_completed"
                name={t('dashboard.tasks_completed')}
                stroke="var(--status-warning)"
                strokeWidth={2}
                strokeDasharray="5 5"
                dot={{ fill: 'var(--status-warning)', strokeWidth: 0, r: 3 }}
                activeDot={{ r: 5, strokeWidth: 0 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  );
};

export default MonthlyTrendsChart;
