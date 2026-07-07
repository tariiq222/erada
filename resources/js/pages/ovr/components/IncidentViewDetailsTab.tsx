import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  IconCalendar,
  IconCircleCheck,
  IconClock,
  IconUser,
} from '@tabler/icons-react';
import { contributingFactorLabels } from './constants';
import type { Incident } from './types';
import PatientDataPanel from './IncidentViewPatientDataPanel';

const DATETIME_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
};

interface DetailsTabProps {
  incident: Incident;
}

const IncidentViewDetailsTab: React.FC<DetailsTabProps> = ({ incident }) => {
  const { t } = useTranslation();

  return (
    <div className="space-y-4 pt-2">
      {/* Incident type */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.incident_type')}</p>
          <p className="font-medium text-sm">
            {incident.incident_type?.name || '-'}
          </p>
        </div>
        <div>
          <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.reportable_type')}</p>
          <p className="font-medium text-sm">
            {incident.reportable_incident_type?.name || '-'}
          </p>
        </div>
      </div>

      {/* Date & reporter */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.incident_datetime')}</p>
          <p className="font-medium text-sm flex items-center gap-1">
            <IconCalendar className="h-3 w-3 text-[var(--text-tertiary)]" aria-hidden="true" />
            {incident.incident_datetime
              ? formatDate(incident.incident_datetime, DATETIME_FORMAT)
              : '-'}
          </p>
        </div>
        <div>
          <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.reporter')}</p>
          <p className="font-medium text-sm flex items-center gap-1">
            <IconUser className="h-3 w-3 text-[var(--text-tertiary)]" aria-hidden="true" />
            {incident.reporter?.name || '-'}
          </p>
        </div>
      </div>

      {/* Description */}
      {incident.description && (
        <div className="p-3 bg-[var(--surface-subtle)] rounded-lg">
          <p className="text-xs text-[var(--text-tertiary)] mb-1">{t('ovr.incident_description')}</p>
          <p className="text-sm text-[var(--text-primary)] whitespace-pre-wrap">{incident.description}</p>
        </div>
      )}

      {/* Patient data (masked by default) */}
      <PatientDataPanel incident={incident} />

      {/* Actions & factors */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {incident.actions_taken && (
          <div className="p-3 bg-[var(--surface-subtle)] rounded-lg">
            <p className="text-xs text-[var(--text-tertiary)] mb-1">{t('ovr.actions_taken')}</p>
            <p className="text-sm text-[var(--text-primary)] whitespace-pre-wrap">{incident.actions_taken}</p>
          </div>
        )}
        {incident.contributing_factors &&
          (!Array.isArray(incident.contributing_factors) ||
            incident.contributing_factors.length > 0) && (
            <div className="p-3 bg-[var(--surface-subtle)] rounded-lg">
              <p className="text-xs text-[var(--text-tertiary)] mb-1">{t('ovr.contributing_factors')}</p>
              <p className="text-sm text-[var(--text-primary)] whitespace-pre-wrap">
                {Array.isArray(incident.contributing_factors)
                  ? incident.contributing_factors
                      .map((factor) => t(contributingFactorLabels[factor] ?? factor))
                      .join('، ')
                  : incident.contributing_factors}
              </p>
            </div>
          )}
      </div>

      {/* Authority & closure */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {incident.informed_authority && (
          <div>
            <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.authority_informed')}</p>
            <p className="font-medium text-sm flex items-center gap-1">
              <IconCircleCheck className="h-3 w-3 text-[var(--status-success)]" aria-hidden="true" />
              {incident.authority_informed_at
                ? formatDate(incident.authority_informed_at)
                : t('common.yes')}
            </p>
            {incident.authority_response && (
              <p className="text-sm text-[var(--text-secondary)] mt-1">{incident.authority_response}</p>
            )}
          </div>
        )}
        {incident.closure_reason && (
          <div>
            <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.closure_reason')}</p>
            <p className="font-medium text-sm">{incident.closure_reason}</p>
          </div>
        )}
      </div>

      {/* Due date */}
      {incident.due_date && (
        <div className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
          <IconClock className="h-4 w-4" aria-hidden="true" />
          <span>{t('ovr.due_date')}: {formatDate(incident.due_date)}</span>
        </div>
      )}
    </div>
  );
};

export default IncidentViewDetailsTab;