import React, { useEffect, useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
  Button,
  Input,
  Card,
  CardContent,
  Badge,
} from '@shared/ui';
import { SkipToMain } from '@shared/ui/SkipToMain';
import {IconShieldCheck, IconSearch, IconLoader, IconAlertCircle, IconCalendar, IconCircleCheck, IconActivity, IconFileText, IconClock} from '@tabler/icons-react';
import { publicTrackApi } from '@entities/incident';
import type { ApiError } from '@shared/api/client';
import type { IncidentStatus, SeverityLevel } from './components/types';
import { severityLabels, severityColors, statusLabels, statusColors } from './components/constants';

interface TrackTimelineEntry {
  to_status: IncidentStatus;
  at: string;
}

interface TrackReport {
  report_number: string;
  status: IncidentStatus;
  status_label: string;
  severity_level: SeverityLevel;
  incident_type: string | null;
  submitted_at: string | null;
  resolved_at: string | null;
  timeline: TrackTimelineEntry[];
}

interface TrackResponse {
  data: TrackReport;
}

type PageState = 'idle' | 'loading' | 'success' | 'not_found' | 'error';

const DATE_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
};

const PublicTrackReport: React.FC = () => {
  const { t } = useTranslation();
  const { tracking_token: trackingTokenParam } = useParams<{ tracking_token: string }>();

  const [query, setQuery] = useState<string>(trackingTokenParam ?? '');
  const [pageState, setPageState] = useState<PageState>('idle');
  const [report, setReport] = useState<TrackReport | null>(null);

  const track = useCallback(async (trackingToken: string) => {
    const trimmed = trackingToken.trim();
    if (!trimmed) return;

    setPageState('loading');
    setReport(null);

    try {
      const response = (await publicTrackApi.track(trimmed)) as TrackResponse;
      setReport(response.data);
      setPageState('success');
    } catch (err) {
      const status = (err as ApiError)?.status;
      setPageState(status === 404 ? 'not_found' : 'error');
    }
  }, []);

  // Auto-track when a tracking token is provided in the URL
  useEffect(() => {
    if (trackingTokenParam) {
      void track(trackingTokenParam);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [trackingTokenParam]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    void track(query);
  };

  return (
    <div
      id="main-content"
      dir="rtl"
      className="min-h-screen flex items-center justify-center bg-[var(--bg-secondary)] px-4 py-10"
    >
      <SkipToMain label={t('a11y.skip_to_main')} />
      <div className="w-full max-w-xl space-y-6">
        {/* Header */}
        <div className="flex flex-col items-center text-center gap-3">
          <div className="h-14 w-14 rounded-2xl bg-[var(--accent-default)] flex items-center justify-center shadow-sm">
            <IconShieldCheck className="h-7 w-7 text-[var(--text-inverse)]" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-[var(--text-primary)]">
              {t('ovr.track_title')}
            </h1>
            <p className="text-sm text-[var(--text-secondary)] mt-1">
              {t('ovr.track_subtitle')}
            </p>
          </div>
        </div>

        {/* IconSearch Card */}
        <Card>
          <CardContent className="p-5">
            <form onSubmit={handleSubmit} className="flex flex-col sm:flex-row gap-3">
              <div className="flex-1">
                <Input
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder={t('ovr.enter_report_number')}
                  aria-label={t('ovr.report_number')}
                />
              </div>
              <Button
                type="submit"
                disabled={pageState === 'loading' || query.trim().length === 0}
                className="flex items-center justify-center gap-2 whitespace-nowrap"
              >
                {pageState === 'loading' ? (
                  <IconLoader className="h-4 w-4 animate-spin" />
                ) : (
                  <IconSearch className="h-4 w-4" />
                )}
                {t('ovr.track_button')}
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Results panel */}
        <div
          role="status"
          aria-live="polite"
          aria-atomic="true"
          aria-label={t('ovr.track_results_label')}
        >
        {pageState === 'not_found' && (
          <Card>
            <CardContent className="p-6 flex flex-col items-center text-center gap-2">
              <div className="h-12 w-12 rounded-full bg-[var(--status-warning-subtle)] flex items-center justify-center">
                <IconAlertCircle className="h-6 w-6 text-[var(--status-warning)]" />
              </div>
              <p className="text-sm font-medium text-[var(--text-primary)]">
                {t('ovr.report_not_found')}
              </p>
            </CardContent>
          </Card>
        )}

        {pageState === 'error' && (
          <Card>
            <CardContent className="p-6 flex flex-col items-center text-center gap-2">
              <div className="h-12 w-12 rounded-full bg-[var(--status-danger-subtle)] flex items-center justify-center">
                <IconAlertCircle className="h-6 w-6 text-[var(--status-danger)]" />
              </div>
              <p className="text-sm font-medium text-[var(--text-primary)]">
                {t('ovr.track_error')}
              </p>
            </CardContent>
          </Card>
        )}

        {pageState === 'success' && report && (
          <Card>
            <CardContent className="p-6 space-y-5">
              {/* Report number + status */}
              <div className="flex items-center justify-between flex-wrap gap-3 pb-4 border-b border-[var(--border-default)]">
                <div>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {t('ovr.report_number')}
                  </p>
                  <p className="text-lg font-bold text-[var(--text-primary)] font-mono">
                    {report.report_number}
                  </p>
                </div>
                <Badge variant={statusColors[report.status] ?? 'default'} size="md">
                  {report.status_label || t(statusLabels[report.status] ?? report.status)}
                </Badge>
              </div>

              {/* Meta grid */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="flex items-start gap-2">
                  <IconActivity className="h-4 w-4 mt-0 text-[var(--text-secondary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('ovr.severity')}</p>
                    <Badge variant={severityColors[report.severity_level] ?? 'default'} size="sm">
                      {t(severityLabels[report.severity_level] ?? report.severity_level)}
                    </Badge>
                  </div>
                </div>

                <div className="flex items-start gap-2">
                  <IconFileText className="h-4 w-4 mt-0 text-[var(--text-secondary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('ovr.incident_type')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">
                      {report.incident_type || '-'}
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-2">
                  <IconCalendar className="h-4 w-4 mt-0 text-[var(--text-secondary)]" />
                  <div>
                    <p className="text-xs text-[var(--text-secondary)]">{t('ovr.submitted_at')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">
                      {report.submitted_at ? formatDate(report.submitted_at, DATE_FORMAT) : '-'}
                    </p>
                  </div>
                </div>

                {report.resolved_at && (
                  <div className="flex items-start gap-2">
                    <IconCircleCheck className="h-4 w-4 mt-0 text-[var(--status-success)]" />
                    <div>
                      <p className="text-xs text-[var(--text-secondary)]">{t('ovr.resolved_at')}</p>
                      <p className="text-sm font-medium text-[var(--text-primary)]">
                        {formatDate(report.resolved_at, DATE_FORMAT)}
                      </p>
                    </div>
                  </div>
                )}
              </div>

              {/* Timeline */}
              <div className="pt-2">
                <div className="flex items-center gap-2 mb-3">
                  <IconClock className="h-4 w-4 text-[var(--text-secondary)]" />
                  <p className="text-sm font-semibold text-[var(--text-primary)]">
                    {t('ovr.status_history')}
                  </p>
                </div>

                {report.timeline.length === 0 ? (
                  <p className="text-sm text-[var(--text-secondary)] text-center py-4">
                    {t('ovr.no_status_history')}
                  </p>
                ) : (
                  <div className="space-y-0">
                    {report.timeline.map((entry, idx) => (
                      <div key={`${entry.to_status}-${entry.at}-${idx}`} className="flex gap-3">
                        <div className="flex flex-col items-center">
                          <div className="h-2.5 w-2.5 rounded-full bg-[var(--accent-default)]" />
                          {idx < report.timeline.length - 1 && (
                            <div className="w-px flex-1 bg-[var(--border-default)] my-1" />
                          )}
                        </div>
                        <div className="pb-5">
                          <Badge variant={statusColors[entry.to_status] ?? 'default'} size="sm">
                            {t(statusLabels[entry.to_status] ?? entry.to_status)}
                          </Badge>
                          <p className="text-xs text-[var(--text-secondary)] mt-1">
                            {formatDate(entry.at, DATE_FORMAT)}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}
        </div>
      </div>
    </div>
  );
};

export default PublicTrackReport;
