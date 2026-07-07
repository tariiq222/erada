import React from 'react';
import { Card, CardContent } from '@shared/ui';
import {IconTrendingUp, IconTrendingDown, IconAlertTriangle, IconWallet, IconChartPie} from '@tabler/icons-react';
import { ExpenseStats, formatCurrency } from './types';

interface ExpenseStatsCardsProps {
  budget: number | null;
  stats: ExpenseStats | null;
}

const ExpenseStatsCards: React.FC<ExpenseStatsCardsProps> = ({ budget, stats }) => {
  return (
    <>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {/* الميزانية */}
        <Card>
          <CardContent className="p-3">
            <div className="flex items-center gap-2">
              <div className="p-1 bg-[var(--accent-subtle)] rounded-lg">
                <IconWallet className="h-4 w-4 text-[var(--accent-default)]" />
              </div>
              <div>
                <p className="text-[10px] text-[var(--text-tertiary)] font-medium">الميزانية</p>
                <p className="text-base font-bold text-[var(--accent-default)]">
                  {budget ? formatCurrency(budget) : '-'}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* المصروف */}
        <Card>
          <CardContent className="p-3">
            <div className="flex items-center gap-2">
              <div className="p-1 bg-[var(--surface-muted)] rounded-lg">
                <IconTrendingDown className="h-4 w-4 text-[var(--text-primary)]" />
              </div>
              <div>
                <p className="text-[10px] text-[var(--text-tertiary)] font-medium">المصروف</p>
                <p className="text-base font-bold text-[var(--text-primary)]">
                  {formatCurrency(stats?.spent_amount || 0)}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* المتبقي */}
        <Card>
          <CardContent className="p-3">
            <div className="flex items-center gap-2">
              <div
                className={`p-1 rounded-lg ${
                  (stats?.remaining || 0) < 0
                    ? 'bg-[var(--status-danger-subtle)]'
                    : 'bg-[var(--status-success-subtle)]'
                }`}
              >
                <IconTrendingUp
                  className={`h-4 w-4 ${
                    (stats?.remaining || 0) < 0
                      ? 'text-[var(--status-danger-text)]'
                      : 'text-[var(--status-success-text)]'
                  }`}
                />
              </div>
              <div>
                <p className="text-[10px] text-[var(--text-tertiary)] font-medium">المتبقي</p>
                <p
                  className={`text-base font-bold ${
                    (stats?.remaining || 0) < 0
                      ? 'text-[var(--status-danger-text)]'
                      : 'text-[var(--status-success-text)]'
                  }`}
                >
                  {formatCurrency(stats?.remaining || 0)}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* نسبة الاستهلاك */}
        <Card>
          <CardContent className="p-3">
            <div className="flex items-center gap-2">
              <div
                className={`p-1 rounded-lg ${
                  (stats?.percentage_used || 0) >= 80
                    ? 'bg-[var(--status-danger-subtle)]'
                    : 'bg-[var(--surface-muted)]'
                }`}
              >
                <IconChartPie
                  className={`h-4 w-4 ${
                    (stats?.percentage_used || 0) >= 80
                      ? 'text-[var(--status-danger-text)]'
                      : 'text-[var(--text-primary)]'
                  }`}
                />
              </div>
              <div>
                <p className="text-[10px] text-[var(--text-tertiary)] font-medium">الاستهلاك</p>
                <p
                  className={`text-base font-bold ${
                    (stats?.percentage_used || 0) >= 80
                      ? 'text-[var(--status-danger-text)]'
                      : 'text-[var(--text-primary)]'
                  }`}
                >
                  {stats?.percentage_used || 0}%
                </p>
              </div>
            </div>
            {(stats?.percentage_used || 0) >= 80 && (
              <div className="mt-1 flex items-center gap-1 text-[10px] text-[var(--status-danger-text)]">
                <IconAlertTriangle className="h-3 w-3" />
                <span>تحذير!</span>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* شريط التقدم */}
      {budget && budget > 0 && (
        <div className="px-1">
          <div className="flex items-center justify-between mb-1">
            <span className="text-xs text-[var(--text-tertiary)]">استهلاك الميزانية</span>
            <span className="text-xs font-medium text-[var(--text-secondary)]">
              {stats?.percentage_used || 0}%
            </span>
          </div>
          <div className="h-2 bg-[var(--surface-muted)] rounded-full overflow-hidden">
            <div
              className={`h-full rounded-full transition-[width] duration-500 ${
                (stats?.percentage_used || 0) >= 100
                  ? 'bg-[var(--status-danger)]'
                  : (stats?.percentage_used || 0) >= 80
                  ? 'bg-[var(--status-warning)]'
                  : 'bg-[var(--status-success)]'
              }`}
              style={{ width: `${Math.min(stats?.percentage_used || 0, 100)}%` }}
            />
          </div>
        </div>
      )}
    </>
  );
};

export default ExpenseStatsCards;
