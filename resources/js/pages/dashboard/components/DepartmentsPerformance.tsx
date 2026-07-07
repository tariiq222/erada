import React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Card, CardHeader, CardTitle, CardContent, Progress } from '@shared/ui';
import {IconBuilding, IconArrowLeft, IconAlertCircle} from '@tabler/icons-react';

interface DepartmentPerformance {
  id: number;
  name: string;
  total_projects: number;
  completed: number;
  active: number;
  overdue: number;
  completion_rate: number;
}

interface DepartmentsPerformanceProps {
  data: DepartmentPerformance[];
  loading?: boolean;
}

export const DepartmentsPerformance: React.FC<DepartmentsPerformanceProps> = ({
  data,
  loading,
}) => {
  const { t } = useTranslation();
  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconBuilding className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.departments_performance')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-3 animate-pulse">
            {[1, 2, 3, 4, 5].map((i) => (
              <div key={i} className="flex items-center gap-3">
                <div className="h-4 bg-[var(--surface-muted)] rounded w-1/3" />
                <div className="h-2 bg-[var(--surface-muted)] rounded flex-1" />
                <div className="h-4 bg-[var(--surface-muted)] rounded w-12" />
              </div>
            ))}
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
            <IconBuilding className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.departments_performance')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-48 flex flex-col items-center justify-center text-[var(--text-tertiary)]">
            <IconBuilding className="h-10 w-10 mb-2 opacity-50" />
            <p className="text-sm">{t('dashboard.no_departments_data')}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  // Sort by completion rate descending
  const sortedData = [...data].sort((a, b) => b.completion_rate - a.completion_rate);

  const getProgressTone = (rate: number): 'success' | 'warning' | 'danger' => {
    if (rate >= 75) return 'success';
    if (rate >= 50) return 'warning';
    return 'danger';
  };

  const toneColor: Record<'success' | 'warning' | 'danger', string> = {
    success: 'var(--status-success)',
    warning: 'var(--status-warning)',
    danger: 'var(--status-danger)',
  };

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <div className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconBuilding className="h-4 w-4 text-[var(--accent-default)]" />
            {t('dashboard.departments_performance')}
          </CardTitle>
          <Link
            to="/hr/departments"
            className="text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)] flex items-center gap-1"
          >
            {t('common.view_all')}
            <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
          </Link>
        </div>
      </CardHeader>
      <CardContent className="p-4">
        <div className="space-y-3">
          {sortedData.slice(0, 5).map((dept) => (
            <div
              key={dept.id}
              className="p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] transition-colors"
            >
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-2 min-w-0">
                  <span className="text-sm font-medium text-[var(--text-primary)] truncate">
                    {dept.name}
                  </span>
                  {dept.overdue > 0 && (
                    <span className="flex items-center gap-1 text-xs text-[var(--status-danger)]">
                      <IconAlertCircle className="h-3 w-3" />
                      {dept.overdue}
                    </span>
                  )}
                </div>
                <span
                  className="text-sm font-bold"
                  style={{ color: toneColor[getProgressTone(dept.completion_rate)] }}
                >
                  {dept.completion_rate}%
                </span>
              </div>
              <Progress
                value={dept.completion_rate}
                size="sm"
                tone={getProgressTone(dept.completion_rate)}
              />
              <div className="flex items-center justify-between mt-2 text-xs text-[var(--text-tertiary)]">
                <span>{dept.total_projects} {t('nav.projects')}</span>
                <div className="flex items-center gap-3">
                  <span className="text-[var(--status-success)]">✓ {dept.completed}</span>
                  <span className="text-[var(--accent-default)]">● {dept.active}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
};

export default DepartmentsPerformance;
