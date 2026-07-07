import type { CustomBadgeColor } from '@shared/ui';
import type { PerformanceKPI } from '@entities/performance';

export const KPI_FREQUENCIES = [
  { value: 'daily', labelKey: 'performance.frequency.daily' },
  { value: 'weekly', labelKey: 'performance.frequency.weekly' },
  { value: 'monthly', labelKey: 'performance.frequency.monthly' },
  { value: 'quarterly', labelKey: 'performance.frequency.quarterly' },
  { value: 'yearly', labelKey: 'performance.frequency.yearly' },
] as const;

export const KPI_DIRECTIONS = [
  { value: 'increase', labelKey: 'performance.direction.increase' },
  { value: 'decrease', labelKey: 'performance.direction.decrease' },
  { value: 'maintain', labelKey: 'performance.direction.maintain' },
] as const;

export const KPI_STATUSES = [
  { value: 'active', labelKey: 'performance.status.active' },
  { value: 'inactive', labelKey: 'performance.status.inactive' },
  { value: 'archived', labelKey: 'performance.status.archived' },
] as const;

type ApiError = {
  message?: unknown;
  response?: {
    data?: {
      message?: unknown;
    };
  };
};

const clampPercentage = (value: number) => Math.min(100, Math.max(0, Math.round(value)));

const numericValue = (value: number | string | null | undefined) => {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
};

export const statusColor = (status?: string | null): CustomBadgeColor => {
  if (status === 'active') return 'success';
  if (status === 'inactive') return 'warning';
  if (status === 'archived') return 'secondary';
  return 'secondary';
};

export const statusLabelKey = (status?: string | null) => {
  if (status === 'active') return 'performance.status.active';
  if (status === 'inactive') return 'performance.status.inactive';
  if (status === 'archived') return 'performance.status.archived';
  return 'performance.status.unknown';
};

export const performanceColor = (status?: string | null): CustomBadgeColor => {
  if (status === 'on_track') return 'success';
  if (status === 'at_risk') return 'warning';
  if (status === 'off_track') return 'danger';
  return 'secondary';
};

export const performanceLabelKey = (status?: string | null) => {
  if (status === 'on_track') return 'performance.performance_status.on_track';
  if (status === 'at_risk') return 'performance.performance_status.at_risk';
  if (status === 'off_track') return 'performance.performance_status.off_track';
  return 'performance.performance_status.unknown';
};

export const achievement = (kpi: PerformanceKPI) => {
  if (kpi.achievement_percentage !== null && kpi.achievement_percentage !== undefined) {
    return clampPercentage(kpi.achievement_percentage);
  }

  const baseline = numericValue(kpi.baseline) ?? 0;
  const current = numericValue(kpi.current_value) ?? baseline;
  const target = numericValue(kpi.target);

  if (target === null) {
    return 0;
  }

  if (kpi.direction === 'decrease') {
    const range = baseline - target;
    return range === 0 ? clampPercentage(current <= target ? 100 : 0) : clampPercentage(((baseline - current) / range) * 100);
  }

  if (kpi.direction === 'maintain') {
    const range = Math.abs(target) || 1;
    return clampPercentage(100 - (Math.abs(current - target) / range) * 100);
  }

  const range = target - baseline;
  return range === 0 ? clampPercentage(current >= target ? 100 : 0) : clampPercentage(((current - baseline) / range) * 100);
};

export const displayValue = (value?: number | string | null, unit?: string | null) => {
  if (value === undefined || value === null || value === '') return '-';
  return unit ? `${value} ${unit}` : String(value);
};

export const formatDate = (value?: string | null) => {
  if (!value) return '-';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return date.toLocaleDateString();
};

export const todayInputValue = () => new Date().toISOString().slice(0, 10);

export const getErrorMessage = (error: unknown, fallback: string) => {
  const details = error as ApiError;

  if (typeof details?.response?.data?.message === 'string') {
    return details.response.data.message;
  }

  if (typeof details?.message === 'string') {
    return details.message;
  }

  return fallback;
};
