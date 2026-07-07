import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, Progress, StatStrip } from '@shared/ui';
import {IconCalendar, IconBuilding, IconUsers} from '@tabler/icons-react';
import type { Program } from './types';
import { formatDate } from './types';

interface OverviewTabProps {
  program: Program;
  projectsCount: number;
  inProgressProjects: number;
  completedProjects: number;
  avgProgress: number;
}

const OverviewTab: React.FC<OverviewTabProps> = ({
  program,
  projectsCount,
  inProgressProjects,
  completedProjects,
  avgProgress,
}) => {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {/* Main Info */}
      <div className="lg:col-span-2 space-y-6">
        {program.description && (
          <Card>
            <CardContent className="p-6">
              <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-2">{t('common.description')}</h3>
              <p className="text-[var(--text-primary)]">{program.description}</p>
            </CardContent>
          </Card>
        )}

        {/* نسبة الإنجاز التفصيلية */}
        <Card>
          <CardContent className="p-6">
            <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('strategy.programs.programProgress')}</h3>
            <div className="space-y-4">
              <div>
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm text-[var(--text-secondary)]">{t('strategy.programs.overallProgress')}</span>
                  <span className="text-lg font-bold text-[var(--text-primary)]">{program.progress}%</span>
                </div>
                <Progress value={program.progress} size="md" />
              </div>
              {program.budget && program.budget > 0 && (
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm text-[var(--text-secondary)]">{t('strategy.programs.budgetUtilization')}</span>
                    <span className="text-lg font-bold text-[var(--text-primary)]">{program.budget_utilization}%</span>
                  </div>
                  <Progress value={program.budget_utilization} size="md" />
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* ملخص المشاريع */}
        <Card>
          <CardContent className="p-6">
            <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('strategy.programs.projectsSummary')}</h3>
            <StatStrip
              items={[
                { label: t('common.total'), value: projectsCount },
                { label: t('status.in_progress'), value: inProgressProjects, tone: 'accent' },
                { label: t('status.completed'), value: completedProjects, tone: 'success' },
                { label: t('strategy.programs.avgProgress'), value: `${avgProgress}%` },
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
              {program.start_date && (
                <div className="flex items-center gap-3">
                  <IconCalendar className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('common.startDate')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{formatDate(program.start_date)}</p>
                  </div>
                </div>
              )}
              {program.end_date && (
                <div className="flex items-center gap-3">
                  <IconCalendar className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('common.endDate')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{formatDate(program.end_date)}</p>
                  </div>
                </div>
              )}
              {program.department && (
                <div className="flex items-center gap-3">
                  <IconBuilding className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('common.department')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{program.department.name}</p>
                  </div>
                </div>
              )}
              {program.program_manager && (
                <div className="flex items-center gap-3">
                  <IconUsers className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('strategy.programs.programManager')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{program.program_manager.name}</p>
                  </div>
                </div>
              )}
              {program.owner && (
                <div className="flex items-center gap-3">
                  <IconUsers className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('common.owner')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{program.owner.name}</p>
                  </div>
                </div>
              )}
              {program.executive_sponsor && (
                <div className="flex items-center gap-3">
                  <IconUsers className="w-4 h-4 text-[var(--text-tertiary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('strategy.programs.executiveSponsor')}</p>
                    <p className="text-sm text-[var(--text-primary)]">{program.executive_sponsor.name}</p>
                  </div>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {program.budget && (
          <Card>
            <CardContent className="p-6">
              <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-4">{t('common.budget')}</h3>
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-sm text-[var(--text-secondary)]">{t('common.total')}</span>
                  <span className="font-bold text-[var(--text-primary)]">
                    {program.budget.toLocaleString()} {t('common.currency')}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-sm text-[var(--text-secondary)]">{t('common.spent')}</span>
                  <span className="font-bold text-[var(--status-warning)]">
                    {(program.spent_amount || 0).toLocaleString()} {t('common.currency')}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-sm text-[var(--text-secondary)]">{t('common.remaining')}</span>
                  <span className="font-bold text-[var(--status-success-text)]">
                    {(program.budget - (program.spent_amount || 0)).toLocaleString()} {t('common.currency')}
                  </span>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
};

export default OverviewTab;
