import React from 'react';
// Phase 4C — direct leaf imports (not via @shared/ui barrel). Same
// rationale as FilterRow: avoid a circular chunk shape on every
// code split.
import { Card, CardContent } from './Card';
import {type LucideIcon} from '@tabler/icons-react';

export type StatCardColor = 'accent' | 'success' | 'warning' | 'danger' | 'info';

export interface StatCardProps {
  label: string;
  value: number | string;
  icon: LucideIcon;
  color?: StatCardColor;
}

const colorMap: Record<StatCardColor, string> = {
  accent: 'bg-[var(--accent-default)]',
  success: 'bg-[var(--status-success)]',
  warning: 'bg-[var(--status-warning)]',
  danger: 'bg-[var(--status-danger)]',
  info: 'bg-[var(--status-info)]',
};

export const StatCard: React.FC<StatCardProps> = ({
  label,
  value,
  icon: Icon,
  color = 'accent',
}) => (
  <Card>
    <CardContent className="p-3 sm:p-4">
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs font-medium text-[var(--text-tertiary)] truncate">{label}</p>
          <p className="text-lg sm:text-2xl font-bold text-[var(--text-primary)] mt-0 sm:mt-1">{value}</p>
        </div>
        <div
          data-testid="stat-card-icon"
          data-color={color}
          className={`h-8 w-8 sm:h-10 sm:w-10 rounded-lg ${colorMap[color]} flex items-center justify-center shrink-0`}
        >
          <Icon className="h-4 w-4 sm:h-5 sm:w-5 text-[var(--text-inverse)]" />
        </div>
      </div>
    </CardContent>
  </Card>
);

export default StatCard;
