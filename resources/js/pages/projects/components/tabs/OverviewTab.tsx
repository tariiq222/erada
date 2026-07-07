import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { Card, CardContent } from '@shared/ui';
import {IconCalendar, IconTarget, IconFlag, IconCircleCheck, IconCircleX} from '@tabler/icons-react';
import type { ProjectDetails } from '../../types';
import { kanbanColumnStyles } from '../../constants';

interface OverviewTabProps {
  project: ProjectDetails;
}

const OverviewTab: React.FC<OverviewTabProps> = ({ project }) => {
  const { t } = useTranslation();
  return (
    <div className="space-y-4">
      {/* الوصف – مدخل قراءة */}
      {project.description && (
        <p className="max-w-[75ch] text-sm text-[var(--text-secondary)] leading-relaxed">
          {project.description}
        </p>
      )}

      {/* الأهداف والنطاق */}
      <div className="grid gap-3 lg:grid-cols-3">
        {/* الأهداف */}
        {project.objectives && project.objectives.length > 0 && (
          <Card>
            <CardContent className="p-3">
              <h4 className="font-medium text-[var(--text-primary)] text-sm mb-1 flex items-center gap-1">
                <IconTarget className="h-4 w-4 text-[var(--text-tertiary)]" />
                {t('projects.objectives')} ({project.objectives.length})
              </h4>
              <ul className="space-y-1">
                {project.objectives.map((obj, i) => (
                  <li key={i} className="flex items-start gap-1 text-sm text-[var(--text-secondary)]">
                    <span className="h-5 w-5 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] flex items-center justify-center text-xs shrink-0">
                      {i + 1}
                    </span>
                    <span className="pt-0">{obj}</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}

        {/* ضمن النطاق */}
        {project.in_scope && project.in_scope.length > 0 && (
          <Card>
            <CardContent className="p-3">
              <h4 className="font-medium text-[var(--text-primary)] text-sm mb-1 flex items-center gap-1">
                <IconCircleCheck className="h-4 w-4 text-[var(--status-success)]" />
                {t('projects.in_scope')} ({project.in_scope.length})
              </h4>
              <ul className="space-y-1">
                {project.in_scope.map((item, i) => (
                  <li key={i} className="flex items-start gap-1 text-sm text-[var(--text-secondary)]">
                    <IconCircleCheck className="h-4 w-4 text-[var(--status-success)] shrink-0 mt-0" />
                    <span>{item}</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}

        {/* خارج النطاق */}
        {project.out_of_scope && project.out_of_scope.length > 0 && (
          <Card>
            <CardContent className="p-3">
              <h4 className="font-medium text-[var(--text-primary)] text-sm mb-1 flex items-center gap-1">
                <IconCircleX className="h-4 w-4 text-[var(--status-danger)]" />
                {t('projects.out_of_scope')} ({project.out_of_scope.length})
              </h4>
              <ul className="space-y-1">
                {project.out_of_scope.map((item, i) => (
                  <li key={i} className="flex items-start gap-1 text-sm text-[var(--text-secondary)]">
                    <IconCircleX className="h-4 w-4 text-[var(--status-danger)] shrink-0 mt-0" />
                    <span>{item}</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>

      {/* ملخص المؤشرات والمخاطر - removed; KPIs and Risks each have their own tab */}
      {/* and their totals are surfaced in ProjectStatsCard. */}

      {/* المراحل */}
      {project.milestones.length > 0 && (
        <Card>
          <CardContent className="p-3">
            <h4 className="font-medium text-[var(--text-primary)] text-sm mb-2 flex items-center gap-1">
              <IconFlag className="h-4 w-4 text-[var(--text-tertiary)]" />
              {t('projects.milestones')} ({project.milestones.length})
            </h4>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {project.milestones.map((milestone, index) => {
                const dueDate = milestone.due_date ? new Date(milestone.due_date) : null;
                const isOverdue = dueDate && new Date() > dueDate && milestone.status !== 'completed';
                const style = kanbanColumnStyles[milestone.status] || kanbanColumnStyles.pending;

                return (
                  <div
                    key={milestone.id}
                    className={`p-2 rounded-lg border ${style.bg} ${isOverdue ? 'border-[var(--status-danger)]' : style.border}`}
                  >
                    <div className="flex items-center gap-2 mb-1">
                      <div className={`h-5 w-5 rounded text-xs font-bold flex items-center justify-center ${style.headerBg} ${style.headerText}`}>
                        {index + 1}
                      </div>
                      <span className="font-medium text-[var(--text-primary)] text-sm truncate flex-1">{milestone.name}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <div className="flex-1">
                        <div className="h-1.5 bg-[var(--surface-base)]/60 rounded-full overflow-hidden">
                          <div
                            className={`h-full rounded-full ${isOverdue ? 'bg-[var(--status-danger)]' : style.progressBg}`}
                            style={{ width: `${milestone.progress || 0}%` }}
                          />
                        </div>
                      </div>
                      <span className={`text-xs font-medium ${style.headerText}`}>{milestone.progress || 0}%</span>
                    </div>
                    {dueDate && (
                      <div className={`text-xs mt-1 ${isOverdue ? 'text-[var(--status-danger)]' : 'text-[var(--text-tertiary)]'}`}>
                        <IconCalendar className="h-3 w-3 inline me-1" />
                        {formatDate(dueDate)}
                        {isOverdue && <span className="text-[var(--status-danger)] font-medium me-1"> ({t('status.delayed')})</span>}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default OverviewTab;
