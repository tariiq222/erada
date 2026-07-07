import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Card, CardHeader, CardTitle, CardContent, StatusBadge, EmptyState } from '@shared/ui';
import type { CustomBadgeColor } from '@shared/ui';
import {IconAlertTriangle, IconArrowLeft, IconFileText, IconClock} from '@tabler/icons-react';
import { formatDateShort } from '@shared/lib/utils';
import { incidentsApi } from '@entities/incident';
import { useAuth } from '@shared/contexts/AuthContext';
import { statusLabels } from '@pages/ovr/components/constants';
import type { Incident, IncidentStatus } from '@pages/ovr/components/types';

interface OVRStats {
  total?: number;
  overdue?: number;
}

// Map OVR incident status -> StatusBadge custom color (StatusBadge has no native OVR type)
const statusBadgeColors: Record<IncidentStatus, CustomBadgeColor> = {
  draft: 'secondary',
  new: 'primary',
  under_review: 'warning',
  pending_info: 'warning',
  in_progress: 'primary',
  resolved: 'success',
  closed: 'success',
  rejected: 'danger',
  archived: 'secondary',
};

export const OVRStatsCard: React.FC = () => {
  const { t } = useTranslation();
  const { canAccess } = useAuth();
  // Phase 9.3 freeze cleanup (2026-07-06): gate on the canonical `ovr.view_*`
  // capabilities. The umbrella `ovr.view_all` is the engine-enforced top-level
  // gate for the dashboard stats card; `ovr.view_own` / `ovr.view_department`
  // stay as granular fallbacks for role-restricted users who can read but not
  // see all departments. No more transition-only strings.
  const canView = canAccess({
    permission: 'ovr.view_statistics',
  }) || canAccess({
    permissions: ['ovr.view_all', 'ovr.view_department', 'ovr.view_own'],
  });
  const [stats, setStats] = useState<OVRStats | null>(null);
  const [recent, setRecent] = useState<Incident[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!canView) {
      setLoading(false);
      return;
    }
    let cancelled = false;

    const load = async () => {
      setLoading(true);
      try {
        const statsData = (await incidentsApi.getStats()) as OVRStats;

        let recentData: Incident[] = [];
        if (typeof incidentsApi.getRecent === 'function') {
          recentData = (await incidentsApi.getRecent(5)) as Incident[];
        } else {
          const response = (await incidentsApi.getAll({ per_page: '5' })) as { data: Incident[] };
          recentData = response.data;
        }

        if (!cancelled) {
          setStats(statsData);
          setRecent(Array.isArray(recentData) ? recentData.slice(0, 5) : []);
        }
      } catch {
        if (!cancelled) {
          setStats(null);
          setRecent([]);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    load();
    return () => {
      cancelled = true;
    };
  }, [canView]);

  if (!canView) return null;

  if (loading) {
    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconAlertTriangle className="h-4 w-4 text-[var(--accent-default)]" />
            {t('ovr.title')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="space-y-3 animate-pulse">
            <div className="grid grid-cols-2 gap-3">
              <div className="h-16 bg-[var(--surface-muted)] rounded-lg" />
              <div className="h-16 bg-[var(--surface-muted)] rounded-lg" />
            </div>
            {[1, 2, 3].map((i) => (
              <div key={i} className="p-3 border border-[var(--border-default)] rounded-lg">
                <div className="h-4 bg-[var(--surface-muted)] rounded w-3/4 mb-2" />
                <div className="h-3 bg-[var(--surface-muted)] rounded w-1/2" />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    );
  }

  const total = stats?.total ?? 0;
  const overdue = stats?.overdue ?? 0;

  return (
    <Card>
      <CardHeader className="border-b border-[var(--border-default)] pb-3">
        <div className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2 text-sm">
            <IconAlertTriangle className="h-4 w-4 text-[var(--accent-default)]" />
            {t('ovr.title')}
          </CardTitle>
          <Link
            to="/ovr/incidents"
            className="text-sm font-medium text-[var(--accent-default)] hover:text-[var(--accent-hover)] flex items-center gap-1"
          >
            {t('common.view_all')}
            <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
          </Link>
        </div>
      </CardHeader>
      <CardContent className="p-4">
        {/* Summary tiles */}
        <div className="grid grid-cols-2 gap-3 mb-4">
          <Link
            to="/ovr/incidents"
            className="p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors"
          >
            <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.total_incidents')}</p>
            <p className="text-2xl font-bold text-[var(--text-primary)] mt-1">{total}</p>
          </Link>
          <Link
            to="/ovr/incidents"
            className="p-3 rounded-lg border transition-colors"
            style={{
              borderColor: overdue > 0 ? 'var(--status-danger)' : 'var(--border-default)',
              backgroundColor: overdue > 0 ? 'var(--status-danger-subtle)' : undefined,
            }}
          >
            <p
              className="text-xs"
              style={{ color: overdue > 0 ? 'var(--status-danger)' : 'var(--text-tertiary)' }}
            >
              {t('ovr.overdue')}
            </p>
            <p
              className="text-2xl font-bold mt-1"
              style={{ color: overdue > 0 ? 'var(--status-danger)' : 'var(--text-primary)' }}
            >
              {overdue}
            </p>
          </Link>
        </div>

        {/* Recent incidents */}
        {recent.length === 0 ? (
          <EmptyState
            icon={IconFileText}
            title={t('ovr.no_incidents')}
            size="md"
          />
        ) : (
          <div className="space-y-2">
            {recent.map((incident) => (
              <Link
                key={incident.id}
                to="/ovr/incidents"
                className="group flex items-center justify-between gap-3 p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors"
              >
                <div className="min-w-0 flex-1">
                  <h4 className="text-sm font-medium text-[var(--text-primary)] truncate">
                    {incident.report_number}
                  </h4>
                  <span className="flex items-center gap-1 mt-1 text-xs text-[var(--text-tertiary)]">
                    <IconClock className="h-3 w-3" />
                    {formatDateShort(incident.created_at)}
                  </span>
                </div>
                <StatusBadge
                  type="custom"
                  status={incident.status}
                  label={t(statusLabels[incident.status])}
                  color={statusBadgeColors[incident.status] ?? 'secondary'}
                  size="sm"
                />
              </Link>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default OVRStatsCard;
