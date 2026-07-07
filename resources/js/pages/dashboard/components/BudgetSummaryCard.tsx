import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent, Progress } from '@shared/ui';
import { formatNumber } from '@shared/lib/utils';
import {IconWallet, IconTrendingUp, IconTrendingDown, IconAlertTriangle} from '@tabler/icons-react';

interface BudgetSummary {
  total_budget: number;
  total_actual: number;
  variance: number;
  variance_percentage: number;
  over_budget_count: number;
}

interface BudgetSummaryCardProps {
  data: BudgetSummary | null;
  loading?: boolean;
}

const formatCurrency = (value: number): string => {
  return formatNumber(value, { maximumFractionDigits: 0 });
};

export const BudgetSummaryCard: React.FC<BudgetSummaryCardProps> = ({ data, loading }) => {
  const { t } = useTranslation();
  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconWallet className="h-4 w-4 text-[var(--status-warning)]" />
            {t('dashboard.budget_summary')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-4 animate-pulse">
            <div className="h-4 bg-[var(--surface-muted)] rounded w-3/4" />
            <div className="h-8 bg-[var(--surface-muted)] rounded" />
            <div className="h-4 bg-[var(--surface-muted)] rounded w-1/2" />
          </div>
        </CardContent>
      </Card>
    );
  }

  if (!data || data.total_budget === 0) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconWallet className="h-4 w-4 text-[var(--status-warning)]" />
            {t('dashboard.budget_summary')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="h-32 flex flex-col items-center justify-center text-[var(--text-tertiary)]">
            <IconWallet className="h-10 w-10 mb-2 opacity-50" />
            <p className="text-sm">{t('dashboard.no_budget_data')}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const spentPercentage = data.total_budget > 0
    ? Math.min((data.total_actual / data.total_budget) * 100, 100)
    : 0;

  const isOverBudget = data.variance < 0;
  const varianceColor = isOverBudget ? 'var(--status-danger)' : 'var(--status-success)';

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <CardTitle className="flex items-center gap-2 text-sm">
          <IconWallet className="h-4 w-4 text-[var(--status-warning)]" />
          {t('dashboard.budget_summary')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 space-y-4">
        {/* Budget vs Actual */}
        <div>
          <div className="flex justify-between items-center mb-2">
            <span className="text-sm text-[var(--text-secondary)]">{t('dashboard.total_budget')}</span>
            <span className="text-sm font-medium text-[var(--text-primary)]">
              {formatCurrency(data.total_budget)} {t('common.sar')}
            </span>
          </div>
          <Progress
            value={spentPercentage}
            size="md"
            tone={isOverBudget ? 'danger' : 'accent'}
          />
          <div className="flex justify-between items-center mt-1">
            <span className="text-xs text-[var(--text-tertiary)]">{t('projects.spent')}</span>
            <span className="text-xs text-[var(--text-tertiary)]">
              {formatCurrency(data.total_actual)} {t('common.sar')} ({spentPercentage.toFixed(1)}%)
            </span>
          </div>
        </div>

        {/* Variance */}
        <div className="flex items-center justify-between p-3 rounded-lg bg-[var(--surface-muted)]">
          <div className="flex items-center gap-2">
            {isOverBudget ? (
              <IconTrendingDown className="h-5 w-5" style={{ color: varianceColor }} />
            ) : (
              <IconTrendingUp className="h-5 w-5" style={{ color: varianceColor }} />
            )}
            <span className="text-sm text-[var(--text-secondary)]">
              {isOverBudget ? t('common.over_budget') : t('projects.remaining')}
            </span>
          </div>
          <div className="text-end">
            <p className="text-lg font-bold" style={{ color: varianceColor }}>
              {isOverBudget ? '-' : '+'}{formatCurrency(Math.abs(data.variance))} {t('common.sar')}
            </p>
            <p className="text-xs text-[var(--text-tertiary)]">
              ({Math.abs(data.variance_percentage).toFixed(1)}%)
            </p>
          </div>
        </div>

        {/* Over budget warning */}
        {data.over_budget_count > 0 && (
          <div className="flex items-center gap-2 p-2 rounded-lg bg-[var(--status-danger-subtle)] border border-[var(--status-danger)]">
            <IconAlertTriangle className="h-4 w-4 text-[var(--status-danger)]" />
            <span className="text-sm text-[var(--status-danger)]">
              {t('dashboard.over_budget_projects')}: {data.over_budget_count}
            </span>
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default BudgetSummaryCard;
