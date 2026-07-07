import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconListCheck, IconClock, IconAlertTriangle, IconFlag, IconCalendarTime, IconCurrencyDollar, IconUsers, IconTrendingUp, IconChartBar} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui';
import type { ProjectDetails } from '../types';

interface ProjectStatsCardProps {
  project: ProjectDetails;
}

const ProjectStatsCard: React.FC<ProjectStatsCardProps> = ({ project }) => {
  const { t } = useTranslation();
  // Task Statistics
  const totalTasks = project.tasks.length;
  const completedTasks = project.tasks.filter(t => t.status === 'completed').length;
  const inProgressTasks = project.tasks.filter(t => t.status === 'in_progress').length;
  const pendingTasks = project.tasks.filter(t => t.status === 'todo').length;
  const overdueTasks = project.tasks.filter(t =>
    t.due_date && new Date(t.due_date) < new Date() && t.status !== 'completed'
  ).length;
  const urgentTasks = project.tasks.filter(t => t.priority === 'urgent' || t.priority === 'high').length;

  // IconFlag Statistics
  const totalMilestones = project.milestones.length;
  const completedMilestones = project.milestones.filter(m => m.status === 'completed').length;

  // Budget Statistics
  const budgetUsed = project.budget && project.actual_cost
    ? Math.round((project.actual_cost / project.budget) * 100)
    : 0;

  // Time Statistics
  const today = new Date();
  const startDate = project.start_date ? new Date(project.start_date) : null;
  const endDate = project.end_date ? new Date(project.end_date) : null;

  let daysRemaining = 0;
  let totalDays = 0;
  let timeProgress = 0;

  if (startDate && endDate) {
    totalDays = Math.ceil((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
    const daysElapsed = Math.ceil((today.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24));
    daysRemaining = Math.max(0, Math.ceil((endDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24)));
    timeProgress = totalDays > 0 ? Math.min(100, Math.round((daysElapsed / totalDays) * 100)) : 0;
  }

  // Risk Statistics
  const highRisks = project.risks.filter(r => r.probability === 'high').length;

  return (
    <Card className="border border-[var(--border-default)] overflow-hidden">
      <CardContent className="p-0">
        {/* Header with Progress */}
        <div className="px-4 py-3 border-b border-[var(--border-default)]">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <div className="h-8 w-8 rounded-lg bg-[var(--accent-subtle)] flex items-center justify-center">
                <IconChartBar className="h-4 w-4 text-[var(--accent-default)]" />
              </div>
              <span className="font-medium text-[var(--text-primary)] text-sm">{t('projects.completion')}</span>
              <span className="text-[var(--text-tertiary)] text-xs">{completedTasks}/{totalTasks}</span>
            </div>
            <span className="text-xl font-bold text-[var(--text-primary)]">{Math.round(project.progress)}%</span>
          </div>
          <div className="h-2 bg-[var(--surface-muted)] rounded-full overflow-hidden mt-2">
            <div
              className="h-full bg-[var(--accent-default)] rounded-full transition-[width] duration-500"
              style={{ width: `${project.progress}%` }}
            />
          </div>
        </div>

        {/* Stats Grid - Horizontal Layout */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 divide-x divide-y sm:divide-y-0 divide-[var(--border-default)] rtl:divide-x-reverse">
          {/* Tasks Stats - أزرق */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--status-info)] text-xs mb-1">
              <IconListCheck className="h-3.5 w-3.5" />
              <span>{t('projects.tasks')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-lg font-bold text-[var(--status-info)]">{totalTasks}</span>
              <span className="text-xs text-[var(--status-success)]">({completedTasks} {t('projects.completed_count')})</span>
            </div>
          </div>

          {/* In Progress - برتقالي */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--status-warning)] text-xs mb-1">
              <IconClock className="h-3.5 w-3.5" />
              <span>{t('status.in_progress')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-lg font-bold text-[var(--status-warning)]">{inProgressTasks}</span>
              <span className="text-xs text-[var(--text-tertiary)]">({pendingTasks} {t('projects.todo_count')})</span>
            </div>
          </div>

          {/* Overdue - أحمر */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--status-danger)] text-xs mb-1">
              <IconAlertTriangle className="h-3.5 w-3.5" />
              <span>{t('projects.overdue')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className={`text-lg font-bold ${overdueTasks > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-muted)]'}`}>{overdueTasks}</span>
              <span className="text-xs text-[var(--status-warning)]">({urgentTasks} {t('projects.urgent_count')})</span>
            </div>
          </div>

          {/* Milestones - بنفسجي */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--accent-default)] text-xs mb-1">
              <IconFlag className="h-3.5 w-3.5" />
              <span>{t('projects.milestones')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-lg font-bold text-[var(--accent-default)]">{completedMilestones}/{totalMilestones}</span>
              <div className="flex-1 max-w-[60px] h-1.5 bg-[var(--surface-muted)] rounded-full overflow-hidden">
                <div className="h-full bg-[var(--accent-default)] rounded-full" style={{ width: `${totalMilestones > 0 ? (completedMilestones / totalMilestones) * 100 : 0}%` }} />
              </div>
            </div>
          </div>

          {/* Time - سماوي */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--status-info)] text-xs mb-1">
              <IconCalendarTime className="h-3.5 w-3.5" />
              <span>{t('projects.time_remaining')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className={`text-lg font-bold ${daysRemaining <= 0 ? 'text-[var(--status-danger)]' : daysRemaining <= 7 ? 'text-[var(--status-warning)]' : 'text-[var(--status-info)]'}`}>{daysRemaining} {t('common.day')}</span>
              <div className="flex-1 max-w-[60px] h-1.5 bg-[var(--surface-muted)] rounded-full overflow-hidden">
                <div className={`h-full rounded-full ${timeProgress >= 90 ? 'bg-[var(--status-danger)]' : timeProgress >= 70 ? 'bg-[var(--status-warning)]' : 'bg-[var(--status-info)]'}`} style={{ width: `${timeProgress}%` }} />
              </div>
            </div>
          </div>

          {/* Budget - أخضر */}
          <div className="px-3 py-2 hover:bg-[var(--surface-hover)] transition-colors">
            <div className="flex items-center gap-1 text-[var(--status-success)] text-xs mb-1">
              <IconCurrencyDollar className="h-3.5 w-3.5" />
              <span>{t('projects.project_budget')}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className={`text-lg font-bold ${!project.budget ? 'text-[var(--text-muted)]' : budgetUsed >= 90 ? 'text-[var(--status-danger)]' : budgetUsed >= 70 ? 'text-[var(--status-warning)]' : 'text-[var(--status-success)]'}`}>{project.budget ? `${budgetUsed}%` : '-'}</span>
              {project.budget && (
                <div className="flex-1 max-w-[60px] h-1.5 bg-[var(--surface-muted)] rounded-full overflow-hidden">
                  <div className={`h-full rounded-full ${budgetUsed >= 90 ? 'bg-[var(--status-danger)]' : budgetUsed >= 70 ? 'bg-[var(--status-warning)]' : 'bg-[var(--status-success)]'}`} style={{ width: `${budgetUsed}%` }} />
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Bottom Stats Row */}
        <div className="border-t border-[var(--border-default)] bg-[var(--surface-subtle)] px-4 py-2">
          <div className="flex flex-wrap items-center justify-between gap-3 text-xs">
            <div className="flex items-center gap-4">
              <span className="text-[var(--text-secondary)]"><IconUsers className="h-3.5 w-3.5 inline me-1" />{project.members.length} {t('projects.member_count_unit')}</span>
              <span className="text-[var(--text-secondary)]"><IconTrendingUp className="h-3.5 w-3.5 inline me-1" />{project.kpis.length} {t('projects.kpi_count_unit')}</span>
              <span className={highRisks > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-secondary)]'}>
                <IconAlertTriangle className="h-3.5 w-3.5 inline me-1" />{project.risks.length} {t('projects.risk_count_unit')}
              </span>
            </div>
            {totalDays > 0 && <span className="text-[var(--text-tertiary)]">{totalDays} {t('projects.total_days')}</span>}
          </div>
        </div>
      </CardContent>
    </Card>
  );
};

export default ProjectStatsCard;
