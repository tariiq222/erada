import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { Badge, Skeleton } from '@shared/ui';
import {IconClock, IconUser, IconArrowRight} from '@tabler/icons-react';
import { incidentsApi } from '@entities/incident';
import { statusLabels, statusColors } from './constants';
import type { IncidentStatus } from './types';

interface StatusChangeEntry {
  type: 'status_change';
  from_status: IncidentStatus | null;
  to_status: IncidentStatus;
  reason: string | null;
  actor: string | null;
  at: string | null;
}

interface ActivityEntry {
  type: 'activity';
  event: string | null;
  description: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  actor: string | null;
  at: string | null;
}

type AuditEntry = StatusChangeEntry | ActivityEntry;

interface AuditResponse {
  data: AuditEntry[];
}

interface AuditLogTabProps {
  reportId: string | number;
}

const dateFormat: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
};

const AuditLogTab: React.FC<AuditLogTabProps> = ({ reportId }) => {
  const { t } = useTranslation();
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    const loadAudit = async () => {
      setLoading(true);
      try {
        const response = (await incidentsApi.getAudit(String(reportId))) as AuditResponse;
        if (active) {
          setEntries(response?.data ?? []);
        }
      } catch {
        if (active) {
          setEntries([]);
        }
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    };

    loadAudit();

    return () => {
      active = false;
    };
  }, [reportId]);

  if (loading) {
    return (
      <div className="space-y-3 pt-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="flex gap-3">
            <Skeleton variant="circular" width={8} height={8} className="mt-1" />
            <div className="flex-1 space-y-2 pb-2">
              <Skeleton variant="text" width="40%" height={16} />
              <Skeleton variant="text" width="70%" height={14} />
            </div>
          </div>
        ))}
      </div>
    );
  }

  if (entries.length === 0) {
    return (
      <p className="text-sm text-[var(--text-secondary)] text-center py-4">
        {t('ovr.no_status_history')}
      </p>
    );
  }

  return (
    <div className="space-y-3 pt-2 max-h-80 overflow-y-auto">
      {entries.map((entry, idx) => (
        <div key={idx} className="flex gap-3">
          <div className="flex flex-col items-center">
            <div className="h-2 w-2 rounded-full bg-[var(--accent-default)] mt-1" />
            {idx < entries.length - 1 && (
              <div className="w-px h-full bg-[var(--border-default)] my-1" />
            )}
          </div>
          <div className="pb-4 flex-1">
            {entry.type === 'status_change' ? (
              <div className="flex items-center gap-2 text-sm flex-wrap">
                {entry.from_status && (
                  <>
                    <span className="text-[var(--text-secondary)] text-xs">
                      {t(statusLabels[entry.from_status])}
                    </span>
                    <IconArrowRight className="h-3 w-3 text-[var(--text-tertiary)]" />
                  </>
                )}
                <Badge variant={statusColors[entry.to_status]} size="sm">
                  {t(statusLabels[entry.to_status])}
                </Badge>
              </div>
            ) : (
              <p className="text-sm font-medium text-[var(--text-primary)]">
                {entry.description || entry.event || t('ovr.audit_log')}
              </p>
            )}

            {entry.type === 'status_change' && entry.reason && (
              <p className="text-sm text-[var(--text-secondary)] mt-1">{entry.reason}</p>
            )}

            <div className="flex items-center gap-2 text-xs text-[var(--text-tertiary)] mt-1">
              <span className="flex items-center gap-1">
                <IconUser className="h-3 w-3" />
                {entry.actor || t('common.system')}
              </span>
              {entry.at && (
                <>
                  <span>•</span>
                  <span className="flex items-center gap-1">
                    <IconClock className="h-3 w-3" />
                    {formatDate(entry.at, dateFormat)}
                  </span>
                </>
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

export default AuditLogTab;
