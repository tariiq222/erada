import React, { useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import {IconFileText, IconCalendar, IconUsers, IconTarget, IconAlertTriangle, IconTrendingUp, IconBuilding, IconFlag, IconCurrencyDollar, IconPrinter} from '@tabler/icons-react';
import { Button, StatusBadge } from '@shared/ui';
import { PROJECT_STATUS_TOKENS } from '@shared/lib/statusTokens';

// ===== الأنواع (Types) =====
interface Deliverable {
  id: number;
  name: string;
  description: string | null;
  status: string;
  progress: number;
}

interface MilestoneWithDeliverables {
  id: number;
  name: string;
  description: string | null;
  start_date: string | null;
  due_date: string | null;
  completed_date: string | null;
  status: string;
  progress: number;
  deliverables?: Deliverable[];
}

interface KPI {
  id: number;
  // The embedded project KPI payload uses `name`; `indicator` is the legacy
  // alias used by the KPIsSection mapper. Accept either.
  name?: string;
  indicator?: string;
  baseline?: string;
  target: string;
  current_value?: string;
}

interface Risk {
  id: number;
  risk: string;
  probability: string;
  impact: string;
  response?: string;
  status: string;
}

interface TaskType {
  id: number;
  title: string;
  status: string;
  priority: string;
  start_date: string | null;
  due_date: string | null;
  assignee: { id: number; name: string } | null;
}

interface Stakeholder {
  id: number;
  name: string;
  role: string;
  organization?: string;
  influence?: string;
}

interface Member {
  id: number;
  name: string;
  pivot: { role: string };
}

interface ProjectReportCardProps {
  project: {
    id: number;
    name: string;
    code: string;
    description?: string | null;
    objectives?: string[] | null;
    status: string;
    priority: string;
    progress: number;
    start_date: string | null;
    end_date: string | null;
    budget: number | null;
    actual_cost: number | null;
    department: { id: number; name: string } | null;
    manager: { id: number; name: string } | null;
    creator?: { id: number; name: string } | null;
    in_scope?: string[] | null;
    out_of_scope?: string[] | null;
    milestones: MilestoneWithDeliverables[];
    kpis: KPI[];
    risks: Risk[];
    tasks?: TaskType[];
    stakeholders?: Stakeholder[];
    members?: Member[];
  };
}

// ===== Design constants =====
// Token-based dot color per project/milestone status (single source of truth).
// Status/priority pills use the shared StatusBadge; this map is only for the
// small milestone status dots that StatusBadge does not cover.
const statusDotColor: Record<string, string> = {
  draft: PROJECT_STATUS_TOKENS.draft.dotColor,
  planning: PROJECT_STATUS_TOKENS.planning.dotColor,
  in_progress: PROJECT_STATUS_TOKENS.in_progress.dotColor,
  on_hold: PROJECT_STATUS_TOKENS.on_hold.dotColor,
  completed: PROJECT_STATUS_TOKENS.completed.dotColor,
  cancelled: PROJECT_STATUS_TOKENS.cancelled.dotColor,
};

// ===== Helpers =====
const formatDateShort = (date: string | null): string => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('ar-SA', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatCurrency = (amount: number | null): string => {
  if (amount === null || amount === undefined) return '-';
  return new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR', maximumFractionDigits: 0 }).format(amount);
};

const calculateRiskLevel = (probability: string, impact: string): string => {
  const levels = { low: 1, medium: 2, high: 3 };
  const score = (levels[probability as keyof typeof levels] || 1) * (levels[impact as keyof typeof levels] || 1);
  if (score >= 6) return 'high';
  if (score >= 3) return 'medium';
  return 'low';
};

const calculateDaysRemaining = (endDate: string | null): { days: number; status: 'normal' | 'warning' | 'urgent' | 'overdue' } | null => {
  if (!endDate) return null;
  const end = new Date(endDate);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);
  const days = Math.ceil((end.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));

  if (days < 0) return { days: Math.abs(days), status: 'overdue' };
  if (days <= 7) return { days, status: 'urgent' };
  if (days <= 30) return { days, status: 'warning' };
  return { days, status: 'normal' };
};

// ===== مكون دائرة الإنجاز المصغرة =====
const MiniProgressRing: React.FC<{ progress: number; size?: number; color?: string }> = ({
  progress,
  size = 40,
  color
}) => {
  const strokeWidth = 4;
  const radius = (size - strokeWidth) / 2;
  const circumference = radius * 2 * Math.PI;
  const offset = circumference - (progress / 100) * circumference;

  const getColor = () => {
    if (color) return color;
    if (progress >= 80) return 'var(--status-success)';
    if (progress >= 50) return 'var(--status-warning)';
    return 'var(--status-danger)';
  };

  return (
    <div className="relative" style={{ width: size, height: size }}>
      <svg className="transform -rotate-90" width={size} height={size}>
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--border-default)"
          strokeWidth={strokeWidth}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={getColor()}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
        />
      </svg>
      <div className="absolute inset-0 flex items-center justify-center">
        <span className="text-[10px] font-bold text-[var(--text-primary)]">{Math.round(progress)}%</span>
      </div>
    </div>
  );
};

// ===== المكون الرئيسي =====
const ProjectReportCard: React.FC<ProjectReportCardProps> = ({ project }) => {
  const { t } = useTranslation();
  const cardRef = useRef<HTMLDivElement>(null);
  const tasks = project.tasks || [];
  const members = project.members || [];

  // حسابات إحصائية
  const totalTasks = tasks.length;
  const completedTasks = tasks.filter(t => t.status === 'completed').length;
  const inProgressTasks = tasks.filter(t => t.status === 'in_progress').length;
  const overdueTasks = tasks.filter(t => t.status !== 'completed' && t.due_date && new Date(t.due_date) < new Date()).length;
  const openRisks = project.risks.filter(r => r.status === 'open').length;
  const highRisks = project.risks.filter(r => calculateRiskLevel(r.probability, r.impact) === 'high').length;
  const completedMilestones = project.milestones.filter(m => m.status === 'completed').length;

  // حساب صحة المشروع
  const calculateHealthScore = (): { score: number; status: 'excellent' | 'good' | 'warning' | 'critical' } => {
    let score = 100;
    const timeInfo = calculateDaysRemaining(project.end_date);
    if (timeInfo?.status === 'overdue') score -= 30;
    else if (timeInfo?.status === 'urgent') score -= 15;
    else if (timeInfo?.status === 'warning') score -= 5;
    if (totalTasks > 0) {
      const overdueRatio = overdueTasks / totalTasks;
      score -= overdueRatio * 25;
    }
    score -= highRisks * 10;
    if (project.budget && project.actual_cost && project.actual_cost > project.budget) {
      const overBudgetRatio = (project.actual_cost - project.budget) / project.budget;
      score -= overBudgetRatio * 20;
    }
    score = Math.max(0, Math.min(100, score));
    if (score >= 80) return { score, status: 'excellent' };
    if (score >= 60) return { score, status: 'good' };
    if (score >= 40) return { score, status: 'warning' };
    return { score, status: 'critical' };
  };

  const health = calculateHealthScore();
  const healthColors = {
    excellent: { bg: 'bg-[var(--status-success)]', border: 'border-[var(--status-success)]', text: 'text-[var(--status-success)]', label: t('projects.report.health_excellent') },
    good: { bg: 'bg-[var(--accent-default)]', border: 'border-[var(--accent-default)]', text: 'text-[var(--accent-default)]', label: t('projects.report.health_good') },
    warning: { bg: 'bg-[var(--status-warning)]', border: 'border-[var(--status-warning)]', text: 'text-[var(--status-warning)]', label: t('projects.report.health_warning') },
    critical: { bg: 'bg-[var(--status-danger)]', border: 'border-[var(--status-danger)]', text: 'text-[var(--status-danger)]', label: t('projects.report.health_critical') },
  };

  const timeInfo = calculateDaysRemaining(project.end_date);
  const budgetUsed = project.budget && project.actual_cost ? Math.round((project.actual_cost / project.budget) * 100) : 0;

  // دالة الطباعة
  const handlePrint = useCallback(() => {
    if (!cardRef.current) return;

    // Build the print document by CLONING the live card into a same-origin
    // hidden iframe. Cloning (Node.cloneNode + adopted into the iframe)
    // preserves the rendered DOM without ever interpolating user-controlled
    // strings into an HTML template, which closes the XSS sink in the previous
    // document.write() implementation (project.code / project.name / card innerHTML).
    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.setAttribute('tabindex', '-1');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const idoc = iframe.contentDocument;
    if (!idoc) {
      document.body.removeChild(iframe);
      return;
    }

    // Static head — no user-controlled interpolation. Title is plain text via
    // textContent so any HTML metacharacters are inert.
    const titleText = `${project.code ?? ''} - ${project.name ?? ''}`;
    idoc.open();
    idoc.write(
      '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8">' +
      '<style>' +
        '*{margin:0;padding:0;box-sizing:border-box}' +
        'body{font-family:"IBM Plex Sans Arabic",system-ui,sans-serif;direction:rtl}' +
        '@page{size:landscape;margin:0}' +
        '@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}' +
      '</style></head><body><div id="print-root" style="width:100vw;height:100vh;padding:20px"></div></body></html>'
    );
    idoc.close();

    const titleEl = idoc.createElement('title');
    titleEl.textContent = titleText; // textContent, not innerHTML — safe
    idoc.head.appendChild(titleEl);

    // Adopt the live card into the iframe document so the cloned nodes render
    // with the same styles (CSS is already in the SPA's stylesheet).
    const root = idoc.getElementById('print-root');
    if (root) {
      const clone = cardRef.current.cloneNode(true) as HTMLElement;
      const adopted = idoc.adoptNode(clone);
      root.appendChild(adopted);
    }

    iframe.onload = () => {
      try {
        iframe.contentWindow?.focus();
        iframe.contentWindow?.print();
      } finally {
        // Give the print dialog time to open before removing the iframe.
        setTimeout(() => {
          if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
        }, 1000);
      }
    };
  }, [project.code, project.name]);

  return (
    <div className="space-y-3">
      {/* Export / print actions */}
      <div className="flex justify-end gap-2">
        <Button
          variant="outline"
          size="sm"
          leftIcon={<IconPrinter className="h-4 w-4" />}
          onClick={handlePrint}
        >
          {t('projects.report.print_export')}
        </Button>
      </div>

      {/* البطاقة الرئيسية - تصميم مضغوط للشريحة */}
      <div
        ref={cardRef}
        className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-xl overflow-hidden"
        style={{ aspectRatio: '16/9', maxWidth: '1200px' }}
      >
        <div className="h-full flex flex-col p-4">
          {/* === Row 1: card header === */}
          <div className="flex items-start justify-between gap-4 pb-3 border-b border-[var(--border-default)]">
            {/* Project info */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <span className="text-xs font-medium text-[var(--text-tertiary)] bg-[var(--surface-muted)] px-2 py-0 rounded">
                  {project.code}
                </span>
                <StatusBadge type="project" status={project.status} size="sm" showDot />
                <StatusBadge type="priority" status={project.priority} size="sm" />
              </div>
              <h2 className="text-lg font-bold text-[var(--text-primary)] truncate">{project.name}</h2>
              {project.description && (
                <p className="text-xs text-[var(--text-tertiary)] line-clamp-1 mt-0">{project.description}</p>
              )}
            </div>

            {/* مؤشر الصحة */}
            <div className="text-center shrink-0">
              <div className={`w-14 h-14 rounded-full ${healthColors[health.status].bg} flex items-center justify-center shadow-sm`}>
                <span className="text-lg font-bold text-[var(--text-inverse)]">{Math.round(health.score)}</span>
              </div>
              <div className={`text-[10px] font-medium mt-1 ${healthColors[health.status].text}`}>
                {healthColors[health.status].label}
              </div>
            </div>
          </div>

          {/* === Row 2: governance info + overall progress === */}
          <div className="flex gap-4 py-3 border-b border-[var(--border-default)]">
            {/* Governance info */}
            <div className="flex-1 grid grid-cols-4 gap-3 text-xs">
              <div>
                <div className="flex items-center gap-1 text-[var(--text-tertiary)] mb-0">
                  <IconBuilding className="w-3 h-3" />
                  <span>{t('projects.report.department')}</span>
                </div>
                <div className="font-medium text-[var(--text-primary)] truncate">{project.department?.name || '-'}</div>
              </div>
              <div>
                <div className="flex items-center gap-1 text-[var(--text-tertiary)] mb-0">
                  <IconUsers className="w-3 h-3" />
                  <span>{t('projects.report.leader')}</span>
                </div>
                <div className="font-medium text-[var(--text-primary)] truncate">{project.manager?.name || '-'}</div>
              </div>
              <div>
                <div className="flex items-center gap-1 text-[var(--text-tertiary)] mb-0">
                  <IconCalendar className="w-3 h-3" />
                  <span>{t('projects.report.period')}</span>
                </div>
                <div className="font-medium text-[var(--text-primary)]">
                  {formatDateShort(project.start_date)} - {formatDateShort(project.end_date)}
                </div>
              </div>
              <div>
                <div className="flex items-center gap-1 text-[var(--text-tertiary)] mb-0">
                  <IconCurrencyDollar className="w-3 h-3" />
                  <span>{t('projects.report.budget')}</span>
                </div>
                <div className="font-medium text-[var(--text-primary)]">{formatCurrency(project.budget)}</div>
              </div>
            </div>

            {/* Overall progress */}
            <div className="shrink-0 flex items-center gap-3 px-3 border-s border-[var(--border-default)]">
              <MiniProgressRing progress={project.progress} size={48} />
              <div>
                <div className="text-[10px] text-[var(--text-tertiary)]">{t('projects.report.progress')}</div>
                <div className="text-lg font-bold text-[var(--text-primary)]">{Math.round(project.progress)}%</div>
              </div>
            </div>
          </div>

          {/* === Row 3: quick stats === */}
          <div className="grid grid-cols-8 gap-2 py-3 border-b border-[var(--border-default)]">
            {/* Tasks */}
            <div className="text-center p-2 bg-[var(--status-info-bg)] rounded-lg">
              <div className="text-lg font-bold text-[var(--status-info)]">{totalTasks}</div>
              <div className="text-[10px] text-[var(--status-info)]">{t('projects.report.tasks')}</div>
            </div>
            <div className="text-center p-2 bg-[var(--status-success-bg)] rounded-lg">
              <div className="text-lg font-bold text-[var(--status-success)]">{completedTasks}</div>
              <div className="text-[10px] text-[var(--status-success)]">{t('projects.report.completed')}</div>
            </div>
            <div className="text-center p-2 bg-[var(--status-warning-bg)] rounded-lg">
              <div className="text-lg font-bold text-[var(--status-warning)]">{inProgressTasks}</div>
              <div className="text-[10px] text-[var(--status-warning)]">{t('projects.report.in_progress')}</div>
            </div>
            <div className={`text-center p-2 rounded-lg ${overdueTasks > 0 ? 'bg-[var(--status-danger-bg)]' : 'bg-[var(--surface-muted)]'}`}>
              <div className={`text-lg font-bold ${overdueTasks > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-secondary)]'}`}>{overdueTasks}</div>
              <div className={`text-[10px] ${overdueTasks > 0 ? 'text-[var(--status-danger)]' : 'text-[var(--text-tertiary)]'}`}>{t('projects.report.overdue')}</div>
            </div>
            {/* Milestones */}
            <div className="text-center p-2 bg-[var(--accent-subtle)] rounded-lg">
              <div className="text-lg font-bold text-[var(--accent-default)]">{project.milestones.length}</div>
              <div className="text-[10px] text-[var(--accent-default)]">{t('projects.report.milestones')}</div>
            </div>
            <div className="text-center p-2 bg-[var(--status-success-bg)] rounded-lg">
              <div className="text-lg font-bold text-[var(--status-success)]">{completedMilestones}</div>
              <div className="text-[10px] text-[var(--status-success)]">{t('projects.report.completed')}</div>
            </div>
            {/* Risks */}
            <div className={`text-center p-2 rounded-lg ${openRisks > 0 ? 'bg-[var(--status-warning-bg)]' : 'bg-[var(--surface-muted)]'}`}>
              <div className={`text-lg font-bold ${openRisks > 0 ? 'text-[var(--status-warning)]' : 'text-[var(--text-secondary)]'}`}>{openRisks}</div>
              <div className={`text-[10px] ${openRisks > 0 ? 'text-[var(--status-warning)]' : 'text-[var(--text-tertiary)]'}`}>{t('projects.report.risks')}</div>
            </div>
            {/* Budget spend */}
            <div className={`text-center p-2 rounded-lg ${budgetUsed > 100 ? 'bg-[var(--status-danger-bg)]' : budgetUsed > 80 ? 'bg-[var(--status-warning-bg)]' : 'bg-[var(--status-success-bg)]'}`}>
              <div className={`text-lg font-bold ${budgetUsed > 100 ? 'text-[var(--status-danger)]' : budgetUsed > 80 ? 'text-[var(--status-warning)]' : 'text-[var(--status-success)]'}`}>{budgetUsed}%</div>
              <div className={`text-[10px] ${budgetUsed > 100 ? 'text-[var(--status-danger)]' : budgetUsed > 80 ? 'text-[var(--status-warning)]' : 'text-[var(--status-success)]'}`}>{t('projects.report.spend')}</div>
            </div>
          </div>

          {/* === Row 4: main content (3 columns) === */}
          <div className="flex-1 grid grid-cols-3 gap-3 py-3 min-h-0 overflow-hidden">
            {/* Column 1: milestones */}
            <div className="flex flex-col min-h-0">
              <div className="flex items-center gap-1 mb-2">
                <IconFlag className="w-3.5 h-3.5 text-[var(--accent-default)]" />
                <span className="text-xs font-semibold text-[var(--text-primary)]">{t('projects.report.key_milestones')}</span>
              </div>
              <div className="flex-1 overflow-y-auto space-y-1 pe-1">
                {project.milestones.length > 0 ? (
                  project.milestones.slice(0, 5).map((milestone) => (
                    <div key={milestone.id} className="flex items-center gap-2 p-1 bg-[var(--surface-subtle)] rounded-lg">
                      <MiniProgressRing progress={milestone.progress} size={28} />
                      <div className="flex-1 min-w-0">
                        <div className="text-[11px] font-medium text-[var(--text-primary)] truncate">{milestone.name}</div>
                        <div className="text-[10px] text-[var(--text-tertiary)]">{formatDateShort(milestone.due_date)}</div>
                      </div>
                      <span className={`w-2 h-2 rounded-full shrink-0 ${statusDotColor[milestone.status] || 'bg-[var(--surface-muted)]'}`}></span>
                    </div>
                  ))
                ) : (
                  <div className="text-[11px] text-[var(--text-muted)] text-center py-4">{t('projects.report.no_milestones')}</div>
                )}
              </div>
            </div>

            {/* Column 2: objectives & KPIs */}
            <div className="flex flex-col min-h-0">
              {/* Objectives */}
              {project.objectives && project.objectives.length > 0 && (
                <div className="mb-2">
                  <div className="flex items-center gap-1 mb-1">
                    <IconTarget className="w-3.5 h-3.5 text-[var(--accent-default)]" />
                    <span className="text-xs font-semibold text-[var(--text-primary)]">{t('projects.report.objectives')}</span>
                  </div>
                  <div className="space-y-1">
                    {project.objectives.slice(0, 3).map((obj, idx) => (
                      <div key={idx} className="flex items-start gap-1 text-[11px] text-[var(--text-secondary)]">
                        <span className="w-4 h-4 shrink-0 rounded-full bg-[var(--accent-subtle)] text-[var(--accent-default)] flex items-center justify-center text-[9px] font-bold">
                          {idx + 1}
                        </span>
                        <span className="line-clamp-1">{obj}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* KPIs */}
              <div className="flex-1 min-h-0">
                <div className="flex items-center gap-1 mb-1">
                  <IconTrendingUp className="w-3.5 h-3.5 text-[var(--status-success)]" />
                  <span className="text-xs font-semibold text-[var(--text-primary)]">{t('projects.report.kpis')}</span>
                </div>
                <div className="space-y-1">
                  {project.kpis.length > 0 ? (
                    project.kpis.slice(0, 4).map((kpi) => {
                      // The embedded payload provides `name`; fall back to the
                      // legacy `indicator` alias. baseline may be absent.
                      const kpiLabel = kpi.name ?? kpi.indicator ?? '';
                      const target = parseFloat(kpi.target) || 0;
                      const current = parseFloat(kpi.current_value ?? '') || 0;
                      const performance = target > 0 ? Math.round((current / target) * 100) : 0;
                      return (
                        <div key={kpi.id} className="flex items-center justify-between gap-2 p-1 bg-[var(--surface-subtle)] rounded">
                          <span className="text-[10px] text-[var(--text-secondary)] truncate flex-1">{kpiLabel}</span>
                          <span className={`text-[10px] font-bold ${performance >= 100 ? 'text-[var(--status-success)]' : performance >= 70 ? 'text-[var(--status-warning)]' : 'text-[var(--status-danger)]'}`}>
                            {performance}%
                          </span>
                        </div>
                      );
                    })
                  ) : (
                    <div className="text-[11px] text-[var(--text-muted)] text-center py-2">{t('projects.report.no_kpis')}</div>
                  )}
                </div>
              </div>
            </div>

            {/* Column 3: risks & team */}
            <div className="flex flex-col min-h-0">
              {/* Risks */}
              <div className="mb-2">
                <div className="flex items-center gap-1 mb-1">
                  <IconAlertTriangle className="w-3.5 h-3.5 text-[var(--status-warning)]" />
                  <span className="text-xs font-semibold text-[var(--text-primary)]">{t('projects.report.risks_title')}</span>
                  {highRisks > 0 && (
                    <span className="text-[9px] px-1 py-0 bg-[var(--status-danger-bg)] text-[var(--status-danger)] rounded-full font-medium">
                      {t('projects.report.high_count', { count: highRisks })}
                    </span>
                  )}
                </div>
                <div className="space-y-1">
                  {project.risks.length > 0 ? (
                    project.risks.slice(0, 3).map((risk) => {
                      const level = calculateRiskLevel(risk.probability, risk.impact);
                      return (
                        <div key={risk.id} className="flex items-center gap-2 p-1 bg-[var(--surface-subtle)] rounded">
                          <span className={`w-2 h-2 rounded-full shrink-0 ${level === 'high' ? 'bg-[var(--status-danger)]' : level === 'medium' ? 'bg-[var(--status-warning)]' : 'bg-[var(--status-success)]'}`}></span>
                          <span className="text-[10px] text-[var(--text-secondary)] truncate flex-1">{risk.risk}</span>
                        </div>
                      );
                    })
                  ) : (
                    <div className="text-[11px] text-[var(--text-muted)] text-center py-2">{t('projects.report.no_risks')}</div>
                  )}
                </div>
              </div>

              {/* Team */}
              <div className="flex-1 min-h-0">
                <div className="flex items-center gap-1 mb-1">
                  <IconUsers className="w-3.5 h-3.5 text-[var(--status-info)]" />
                  <span className="text-xs font-semibold text-[var(--text-primary)]">{t('projects.report.team')}</span>
                  <span className="text-[10px] text-[var(--text-tertiary)]">({members.length})</span>
                </div>
                <div className="flex flex-wrap gap-1">
                  {members.slice(0, 8).map((member) => (
                    <div key={member.id} className="flex items-center gap-1 px-1 py-0 bg-[var(--surface-subtle)] rounded-full">
                      <div className="w-4 h-4 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center">
                        <span className="text-[8px] font-bold text-[var(--accent-default)]">{member.name.charAt(0)}</span>
                      </div>
                      <span className="text-[9px] text-[var(--text-secondary)]">{member.name.split(' ')[0]}</span>
                    </div>
                  ))}
                  {members.length > 8 && (
                    <span className="text-[9px] text-[var(--text-tertiary)] px-1 py-0">+{members.length - 8}</span>
                  )}
                </div>
              </div>
            </div>
          </div>

          {/* === Row 5: footer === */}
          <div className="flex items-center justify-between pt-2 border-t border-[var(--border-default)] text-[10px] text-[var(--text-tertiary)]">
            <div className="flex items-center gap-3">
              {timeInfo && (
                <span className={`font-medium ${timeInfo.status === 'overdue' ? 'text-[var(--status-danger)]' : timeInfo.status === 'urgent' ? 'text-[var(--status-warning)]' : 'text-[var(--text-secondary)]'}`}>
                  {timeInfo.status === 'overdue'
                    ? t('projects.report.days_overdue', { count: timeInfo.days })
                    : t('projects.report.days_remaining', { count: timeInfo.days })}
                </span>
              )}
            </div>
            <div className="flex items-center gap-1">
              <IconFileText className="w-3 h-3" />
              <span>{t('projects.report.footer_brand')}</span>
              <span className="text-[var(--text-muted)]">|</span>
              <span>{new Date().toLocaleDateString('ar-SA')}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProjectReportCard;
