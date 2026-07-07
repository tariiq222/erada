import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  Button,
  Badge,
  Modal,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
} from '@shared/ui';
import {IconUser, IconClock, IconCalendar, IconAlertCircle, IconCircleCheck, IconFileText, IconLock, IconStethoscope, IconMessage, IconHistory} from '@tabler/icons-react';
import { incidentsApi } from '@entities/incident';
import type { Incident, Comment } from './types';
import AuditLogTab from './AuditLogTab';
import {
  severityLabels,
  severityColors,
  statusLabels,
  statusColors,
  contributingFactorLabels,
} from './constants';

interface IncidentViewModalProps {
  isOpen: boolean;
  incident: Incident | null;
  onClose: () => void;
}

const IncidentViewModal: React.FC<IncidentViewModalProps> = ({
  isOpen,
  incident,
  onClose,
}) => {
  const { t } = useTranslation();
  const [comments, setComments] = useState<Comment[]>([]);
  const [activeTab, setActiveTab] = useState('details');

  useEffect(() => {
    if (isOpen && incident) {
      setActiveTab('details');
      loadComments();
    }
  }, [isOpen, incident]);

  const loadComments = async () => {
    if (!incident) return;
    try {
      const res = await incidentsApi.getComments(incident.report_number) as Comment[] | { data: Comment[] };
      setComments(Array.isArray(res) ? res : (res?.data ?? []));
    } catch {
      // silently fail
    }
  };

  if (!incident) return null;

  const statusHistory = incident.status_history || [];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`${t('ovr.report')} ${incident.report_number}`}
      size="xl"
    >
      <div className="space-y-4">
        {/* Header badges */}
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div className="flex items-center gap-2">
            <Badge variant={severityColors[incident.severity_level]} size="sm">
              {t(severityLabels[incident.severity_level])}
            </Badge>
            <Badge variant={statusColors[incident.status]} size="sm">
              {t(statusLabels[incident.status])}
            </Badge>
            {incident.is_confidential && (
              <Badge variant="danger" size="sm" className="flex items-center gap-1">
                <IconLock className="h-3 w-3" />
                {t('ovr.confidential')}
              </Badge>
            )}
            {incident.immediate_action_required && (
              <Badge variant="warning" size="sm" className="flex items-center gap-1">
                <IconAlertCircle className="h-3 w-3" />
                {t('ovr.immediate_action_required')}
              </Badge>
            )}
          </div>
          {incident.assigned_to && (
            <div className="flex items-center gap-1 text-sm text-[var(--text-secondary)]">
              <IconUser className="h-4 w-4" />
              {t('ovr.assigned_to')}: {incident.assigned_to.name}
            </div>
          )}
        </div>

        <Tabs defaultValue="details" value={activeTab} onValueChange={setActiveTab}>
          <TabsList>
            <TabsTrigger value="details" icon={<IconFileText className="h-4 w-4" />}>
              {t('ovr.details')}
            </TabsTrigger>
            <TabsTrigger value="history" icon={<IconClock className="h-4 w-4" />}>
              {t('ovr.status_history')}
            </TabsTrigger>
            <TabsTrigger value="comments" icon={<IconMessage className="h-4 w-4" />}>
              {t('ovr.comments')}
            </TabsTrigger>
            <TabsTrigger value="audit" icon={<IconHistory className="h-4 w-4" />}>
              {t('ovr.audit_trail')}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="details">
            <div className="space-y-4 pt-2">
              {/* Incident type */}
              <div className="grid grid-cols-2 gap-4">
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
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.incident_datetime')}</p>
                  <p className="font-medium text-sm flex items-center gap-1">
                    <IconCalendar className="h-3 w-3 text-[var(--text-tertiary)]" />
                    {incident.incident_datetime
                      ? formatDate(incident.incident_datetime, {
                          year: 'numeric',
                          month: '2-digit',
                          day: '2-digit',
                          hour: '2-digit',
                          minute: '2-digit',
                        })
                      : '-'}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.reporter')}</p>
                  <p className="font-medium text-sm flex items-center gap-1">
                    <IconUser className="h-3 w-3 text-[var(--text-tertiary)]" />
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

              {/* Patient data */}
              {incident.is_patient_related && (
                <div className="p-3 bg-[var(--accent-subtle)] rounded-lg border border-[var(--accent-muted)]">
                  <div className="flex items-center gap-2 mb-2">
                    <IconStethoscope className="h-4 w-4 text-[var(--accent-default)]" />
                    <p className="text-sm font-medium text-[var(--accent-default)]">{t('ovr.patient_data')}</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                      <p className="text-xs text-[var(--accent-default)]">{t('ovr.patient_name')}</p>
                      <p className="font-medium text-[var(--text-primary)]">{incident.patient_name || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-[var(--accent-default)]">{t('ovr.patient_file_number')}</p>
                      <p className="font-medium text-[var(--text-primary)]">{incident.patient_file_number || '-'}</p>
                    </div>
                  </div>
                </div>
              )}

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
              <div className="grid grid-cols-2 gap-4">
                {incident.informed_authority && (
                  <div>
                    <p className="text-xs text-[var(--text-tertiary)]">{t('ovr.authority_informed')}</p>
                    <p className="font-medium text-sm flex items-center gap-1">
                      <IconCircleCheck className="h-3 w-3 text-[var(--status-success)]" />
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
                  <IconClock className="h-4 w-4" />
                  <span>{t('ovr.due_date')}: {formatDate(incident.due_date)}</span>
                </div>
              )}
            </div>
          </TabsContent>

          <TabsContent value="history">
            <div className="space-y-3 pt-2 max-h-80 overflow-y-auto">
              {statusHistory.length === 0 ? (
                <p className="text-sm text-[var(--text-tertiary)] text-center py-4">{t('ovr.no_status_history')}</p>
              ) : (
                statusHistory.map((entry, idx) => (
                  <div key={entry.id} className="flex gap-3">
                    <div className="flex flex-col items-center">
                      <div className="h-2 w-2 rounded-full bg-[var(--accent-default)]" />
                      {idx < statusHistory.length - 1 && (
                        <div className="w-px h-full bg-[var(--border-default)] my-1" />
                      )}
                    </div>
                    <div className="pb-4">
                      <div className="flex items-center gap-2 text-sm">
                        <Badge variant={statusColors[entry.to_status]} size="sm">
                          {t(statusLabels[entry.to_status])}
                        </Badge>
                        {entry.from_status && (
                          <>
                            <span className="text-[var(--text-tertiary)]">←</span>
                            <span className="text-[var(--text-tertiary)] text-xs">
                              {t(statusLabels[entry.from_status])}
                            </span>
                          </>
                        )}
                      </div>
                      {entry.reason && (
                        <p className="text-sm text-[var(--text-secondary)] mt-1">{entry.reason}</p>
                      )}
                      <div className="flex items-center gap-2 text-xs text-[var(--text-tertiary)] mt-1">
                        <span>{entry.changed_by?.name || t('common.system')}</span>
                        <span>•</span>
                        <span>{formatDate(entry.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </TabsContent>

          <TabsContent value="comments">
            <div className="space-y-3 pt-2 max-h-80 overflow-y-auto">
              {comments.length === 0 ? (
                <p className="text-sm text-[var(--text-secondary)] text-center py-4">{t('ovr.no_comments')}</p>
              ) : (
                comments.map((comment) => (
                  <div key={comment.id} className="p-3 bg-[var(--surface-subtle)] rounded-lg">
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-sm font-medium">{comment.user?.name || t('common.unknown')}</span>
                      <span className="text-xs text-[var(--text-tertiary)]">
                        {formatDate(comment.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </div>
                    <p className="text-sm text-[var(--text-primary)]">{comment.content}</p>
                  </div>
                ))
              )}
            </div>
          </TabsContent>

          <TabsContent value="audit">
            <AuditLogTab reportId={incident.report_number} />
          </TabsContent>
        </Tabs>

        <div className="flex justify-end pt-4 border-t">
          <Button variant="outline" onClick={onClose}>
            {t('common.close')}
          </Button>
        </div>
      </div>
    </Modal>
  );
};

export default IncidentViewModal;
