import React, { lazy, Suspense, useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  Badge,
  Breadcrumb,
  Button,
  MaskedField,
  PageHeader,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import { SkipToMain } from '@shared/ui/SkipToMain';
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconArrowLeft,
  IconCalendar,
  IconCircleCheck,
  IconClock,
  IconFileText,
  IconHistory,
  IconLock,
  IconMessage,
  IconStethoscope,
  IconUser,
} from '@tabler/icons-react';
import { incidentsApi } from '@entities/incident';
import { useToast } from '@shared/ui/Toast';
import { useCan } from '@shared/api/access';
import {
  severityColors,
  severityLabels,
  statusColors,
  statusLabels,
} from './components/constants';
import type { Comment, Incident } from './components/types';

// Lazy-loaded tab bodies (code-split between tabs)
const DetailsTab = lazy(() => import('./components/IncidentViewDetailsTab'));
const CommentsTab = lazy(() => import('./components/IncidentViewCommentsTab'));
const AuditTab = lazy(() => import('./components/IncidentViewAuditTab'));

type LoadState =
  | { kind: 'loading' }
  | { kind: 'not_found' }
  | { kind: 'error' }
  | { kind: 'ready'; incident: Incident };

const IncidentView: React.FC = () => {
  const { t } = useTranslation();
  const { tracking_token: trackingTokenParam } = useParams<{ tracking_token: string }>();
  const { showToast } = useToast();
  const canEdit = useCan('ovr.edit');
  const [state, setState] = useState<LoadState>({ kind: 'loading' });
  const [comments, setComments] = useState<Comment[]>([]);
  const [activeTab, setActiveTab] = useState<'details' | 'history' | 'comments' | 'audit'>(
    'details',
  );

  useEffect(() => {
    let active = true;
    if (!trackingTokenParam) {
      setState({ kind: 'not_found' });
      return () => {
        active = false;
      };
    }
    setState({ kind: 'loading' });

    const load = async () => {
      try {
        const incident = (await incidentsApi.getOne(trackingTokenParam)) as Incident;
        if (!active) return;
        setState({ kind: 'ready', incident });
      } catch (error: unknown) {
        if (!active) return;
        const status = (error as { status?: number })?.status;
        if (status === 404) {
          setState({ kind: 'not_found' });
        } else {
          setState({ kind: 'error' });
        }
      }
    };

    void load();
    return () => {
      active = false;
    };
  }, [trackingTokenParam]);

  const loadComments = React.useCallback(async (incident: Incident) => {
    try {
      const res = (await incidentsApi.getComments(incident.report_number)) as
        | Comment[]
        | { data: Comment[] };
      setComments(Array.isArray(res) ? res : (res?.data ?? []));
    } catch {
      setComments([]);
    }
  }, []);

  useEffect(() => {
    if (state.kind === 'ready') {
      void loadComments(state.incident);
    }
  }, [state, loadComments]);

  if (state.kind === 'loading') {
    return (
      <div id="main-content" className="space-y-6">
        <SkipToMain label={t('a11y.skip_to_main')} />
        <PageHeader
          icon={IconAlertTriangle}
          iconTone="risk"
          title={t('ovr.view_incident')}
          breadcrumb={
            <Breadcrumb
              items={[
                { label: t('ovr.title'), href: '/ovr/incidents' },
                { label: t('common.loading') },
              ]}
            />
          }
        />
        <p className="text-sm text-[var(--text-secondary)]">{t('common.loading')}</p>
      </div>
    );
  }

  if (state.kind === 'not_found') {
    return (
      <div id="main-content" className="space-y-6">
        <SkipToMain label={t('a11y.skip_to_main')} />
        <PageHeader
          icon={IconAlertTriangle}
          iconTone="risk"
          title={t('ovr.view_incident')}
          breadcrumb={
            <Breadcrumb
              items={[
                { label: t('ovr.title'), href: '/ovr/incidents' },
                { label: t('ovr.not_found') },
              ]}
            />
          }
        />
        <div className="rounded-lg border border-[var(--border-default)] p-6 text-center">
          <p className="text-sm font-medium text-[var(--text-primary)]">
            {t('ovr.report_not_found')}
          </p>
          <Link
            to="/ovr/incidents"
            className="mt-3 inline-flex items-center gap-1 text-sm text-[var(--accent-default)] hover:underline"
          >
            <IconArrowLeft className="h-4 w-4 rtl:rotate-180" aria-hidden="true" />
            {t('ovr.back_to_list')}
          </Link>
        </div>
      </div>
    );
  }

  if (state.kind === 'error') {
    return (
      <div id="main-content" className="space-y-6">
        <SkipToMain label={t('a11y.skip_to_main')} />
        <PageHeader
          icon={IconAlertTriangle}
          iconTone="risk"
          title={t('ovr.view_incident')}
          breadcrumb={
            <Breadcrumb
              items={[
                { label: t('ovr.title'), href: '/ovr/incidents' },
                { label: t('common.error') },
              ]}
            />
          }
        />
        <div className="rounded-lg border border-[var(--status-danger-subtle)] bg-[var(--status-danger-subtle)] p-6 text-center space-y-3">
          <p className="text-sm font-medium text-[var(--status-danger-text)]">
            {t('ovr.load_error')}
          </p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setState({ kind: 'loading' });
              if (trackingTokenParam) {
                void (async () => {
                  try {
                    const incident = (await incidentsApi.getOne(trackingTokenParam)) as Incident;
                    setState({ kind: 'ready', incident });
                  } catch {
                    showToast('error', t('ovr.load_error'));
                    setState({ kind: 'error' });
                  }
                })();
              }
            }}
          >
            {t('common.retry')}
          </Button>
        </div>
      </div>
    );
  }

  const incident = state.incident;
  const statusHistory = incident.status_history ?? [];

  return (
    <div id="main-content" className="space-y-6">
      <SkipToMain label={t('a11y.skip_to_main')} />

      <PageHeader
        icon={IconAlertTriangle}
        iconTone="risk"
        title={`${t('ovr.report')} ${incident.report_number}`}
        breadcrumb={
          <Breadcrumb
            items={[
              { label: t('ovr.title'), href: '/ovr/incidents' },
              { label: incident.report_number },
            ]}
          />
        }
        actions={
          <Link to="/ovr/incidents" aria-label={t('ovr.back_to_list')}>
            <IconButton
              variant="default"
              size="md"
              aria-label={t('ovr.back_to_list')}
              title={t('ovr.back_to_list')}
            >
              <IconArrowLeft className="h-4 w-4 rtl:rotate-180" aria-hidden="true" />
            </IconButton>
          </Link>
        }
        status={
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={severityColors[incident.severity_level] ?? 'default'} size="sm">
              {t(severityLabels[incident.severity_level] ?? incident.severity_level)}
            </Badge>
            <Badge variant={statusColors[incident.status] ?? 'default'} size="sm">
              {t(statusLabels[incident.status] ?? incident.status)}
            </Badge>
            {incident.is_confidential && (
              <Badge variant="danger" size="sm" className="flex items-center gap-1">
                <IconLock className="h-3 w-3" aria-hidden="true" />
                {t('ovr.confidential')}
              </Badge>
            )}
            {incident.immediate_action_required && (
              <Badge variant="warning" size="sm" className="flex items-center gap-1">
                <IconAlertCircle className="h-3 w-3" aria-hidden="true" />
                {t('ovr.immediate_action_required')}
              </Badge>
            )}
          </div>
        }
        metadata={
          <div className="flex flex-wrap items-center gap-3">
            {incident.assigned_to && (
              <div className="flex items-center gap-1 text-sm text-[var(--text-secondary)]">
                <IconUser className="h-4 w-4" aria-hidden="true" />
                <span>
                  {t('ovr.assigned_to')}: {incident.assigned_to.name}
                </span>
              </div>
            )}
            {canEdit && (
              <Link
                to={`/ovr/incidents/${incident.report_number}/edit`}
                className="text-sm text-[var(--accent-default)] hover:underline"
              >
                {t('common.edit')}
              </Link>
            )}
          </div>
        }
      />

      <Tabs
        defaultValue="details"
        value={activeTab}
        onValueChange={(value) => setActiveTab(value as typeof activeTab)}
      >
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
          <Suspense fallback={<p className="text-sm text-[var(--text-secondary)] py-4">{t('common.loading')}</p>}>
            <DetailsTab incident={incident} />
          </Suspense>
        </TabsContent>

        <TabsContent value="history">
          <Suspense fallback={<p className="text-sm text-[var(--text-secondary)] py-4">{t('common.loading')}</p>}>
            <HistoryPanel statusHistory={statusHistory} />
          </Suspense>
        </TabsContent>

        <TabsContent value="comments">
          <Suspense fallback={<p className="text-sm text-[var(--text-secondary)] py-4">{t('common.loading')}</p>}>
            <CommentsTab comments={comments} />
          </Suspense>
        </TabsContent>

        <TabsContent value="audit">
          <Suspense fallback={<p className="text-sm text-[var(--text-secondary)] py-4">{t('common.loading')}</p>}>
            <AuditTab reportId={incident.report_number} />
          </Suspense>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default IncidentView;

// ---- Inline history panel (kept inline; audit/details/comments are lazy) ----

interface HistoryPanelProps {
  statusHistory: NonNullable<Incident['status_history']>;
}

const HISTORY_DATE_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
};

const HistoryPanel: React.FC<HistoryPanelProps> = ({ statusHistory }) => {
  const { t } = useTranslation();
  if (statusHistory.length === 0) {
    return (
      <p className="text-sm text-[var(--text-tertiary)] text-center py-4">
        {t('ovr.no_status_history')}
      </p>
    );
  }
  return (
    <div className="space-y-3 pt-2 max-h-80 overflow-y-auto">
      {statusHistory.map((entry, idx) => (
        <div key={entry.id} className="flex gap-3">
          <div className="flex flex-col items-center">
            <div className="h-2 w-2 rounded-full bg-[var(--accent-default)]" />
            {idx < statusHistory.length - 1 && (
              <div className="w-px h-full bg-[var(--border-default)] my-1" />
            )}
          </div>
          <div className="pb-4">
            <div className="flex items-center gap-2 text-sm flex-wrap">
              <Badge variant={statusColors[entry.to_status] ?? 'default'} size="sm">
                {t(statusLabels[entry.to_status] ?? entry.to_status)}
              </Badge>
              {entry.from_status && (
                <>
                  <span className="text-[var(--text-tertiary)]" aria-hidden="true">←</span>
                  <span className="text-[var(--text-tertiary)] text-xs">
                    {t(statusLabels[entry.from_status] ?? entry.from_status)}
                  </span>
                </>
              )}
            </div>
            {entry.reason && (
              <p className="text-sm text-[var(--text-secondary)] mt-1">{entry.reason}</p>
            )}
            <div className="flex items-center gap-2 text-xs text-[var(--text-tertiary)] mt-1">
              <span>{entry.changed_by?.name || t('common.system')}</span>
              <span aria-hidden="true">•</span>
              <span>{formatDate(entry.created_at, HISTORY_DATE_FORMAT)}</span>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

// Local masked-fields helpers kept here so the lazy tab body stays slim and
// the patient-data block remains co-located with the page.
export { MaskedField as IncidentPatientMaskedField };

// ---- Patient data block (uses MaskedField) ---------------------------------

interface PatientDataPanelProps {
  incident: Incident;
}

export const PatientDataPanel: React.FC<PatientDataPanelProps> = ({ incident }) => {
  const { t } = useTranslation();
  if (!incident.is_patient_related) return null;
  return (
    <div className="p-3 bg-[var(--accent-subtle)] rounded-lg border border-[var(--accent-muted)]">
      <div className="flex items-center gap-2 mb-3">
        <IconStethoscope className="h-4 w-4 text-[var(--accent-default)]" aria-hidden="true" />
        <p className="text-sm font-medium text-[var(--accent-default)]">
          {t('ovr.patient_data')}
        </p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
        <MaskedField
          label={t('ovr.patient_name')}
          value={incident.patient_name ?? ''}
        />
        <MaskedField
          label={t('ovr.patient_file_number')}
          value={incident.patient_file_number ?? ''}
        />
      </div>
    </div>
  );
};

// Re-export calendar icon so other tab bodies can reuse it without re-imports.
export { IconCalendar, IconCircleCheck };