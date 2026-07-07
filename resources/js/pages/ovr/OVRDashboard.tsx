import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts';
import {IconChartBar, IconChartPie, IconAlertTriangle, IconClock} from '@tabler/icons-react';
import { formatDate } from '@shared/lib/utils';
import {
  Card,
  CardHeader,
  CardTitle,
  CardContent,
  Button,
  Badge,
  Skeleton,
  PageHeader,
  StatStrip,
  Select,
  DatePicker,
  FilterButton,
} from '@shared/ui';
import type { StatStripItem } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { SkipToMain } from '@shared/ui/SkipToMain';
import { incidentsApi } from '@entities/incident';
import type { Incident, PaginatedResponse } from './components';
import { severityLabels, statusLabels } from './components';
import { contributingFactorLabels } from './components/constants';
import {
  OVR_STATUS_CHART_TOKENS,
  OVR_SEVERITY_CHART_TOKENS,
  type OvrStatusKey,
  type OvrSeverityKey,
} from '@shared/lib/statusTokens';

type Period = 'all' | 'day' | 'week' | 'month' | 'year';

interface StatsFilters {
  from: string;
  to: string;
  status: string;
  severity: string;
  incident_type_id: string;
  reportable_incident_type_id: string;
  is_patient_related: string;
  patient_gender: string;
  informed_authority: string;
  immediate_action_required: string;
  is_confidential: string;
  reporter_department_id: string;
  contributing_factor: string;
}

interface NamedBreakdownRow {
  id: string | number;
  name?: string | null;
  name_ar?: string | null;
  count: number;
}

interface PatientGenderBreakdownRow {
  id?: string;
  gender: string;
  name?: string | null;
  count: number;
}

interface ContributingFactorBreakdownRow {
  id?: string;
  factor: string;
  name?: string | null;
  count: number;
}

interface MonthlyTrendBreakdownRow {
  month: string;
  count: number;
}

interface IncidentRates {
  patient_related: number;
  informed_authority: number;
  immediate_action_required: number;
  confidential: number;
}

interface IncidentBreakdowns {
  incident_type: NamedBreakdownRow[];
  reportable_type: NamedBreakdownRow[];
  department: NamedBreakdownRow[];
  patient_gender: PatientGenderBreakdownRow[];
  contributing_factor: ContributingFactorBreakdownRow[];
  monthly_trend: MonthlyTrendBreakdownRow[];
}

interface IncidentStats {
  total: number;
  by_status: Record<string, number>;
  by_severity: Record<string, number>;
  patient_related: number;
  informed_authority: number;
  immediate_action_required?: number;
  confidential?: number;
  rates?: IncidentRates;
  breakdowns?: IncidentBreakdowns;
  overdue: number;
  avg_resolution_hours: number;
  period?: { from: string | null; to: string | null };
}

const statusChartColors: Record<OvrStatusKey, string> = OVR_STATUS_CHART_TOKENS;
const severityChartColors: Record<OvrSeverityKey, string> = OVR_SEVERITY_CHART_TOKENS;

const periodOptions: Period[] = ['all', 'day', 'week', 'month', 'year'];

const initialFilters: StatsFilters = {
  from: '',
  to: '',
  status: '',
  severity: '',
  incident_type_id: '',
  reportable_incident_type_id: '',
  is_patient_related: '',
  patient_gender: '',
  informed_authority: '',
  immediate_action_required: '',
  is_confidential: '',
  reporter_department_id: '',
  contributing_factor: '',
};

const OVRDashboard: React.FC = () => {
  const { t, i18n } = useTranslation();
  const { showToast } = useToast();
  const [stats, setStats] = useState<IncidentStats | null>(null);
  const [recent, setRecent] = useState<Incident[]>([]);
  const [period, setPeriod] = useState<Period>('all');
  const [filters, setFilters] = useState<StatsFilters>(initialFilters);
  const [isLoading, setIsLoading] = useState(true);
  const [isFiltersOpen, setIsFiltersOpen] = useState(false);
  const isArabic = i18n?.language?.startsWith('ar') ?? false;

  const fetchData = async (selectedPeriod: Period, selectedFilters: StatsFilters) => {
    setIsLoading(true);
    try {
      const statsParams: Record<string, string> =
        selectedPeriod === 'all' ? {} : { period: selectedPeriod };
      Object.entries(selectedFilters).forEach(([key, value]) => {
        if (value) {
          statsParams[key] = value;
        }
      });
      const [statsData, recentData] = await Promise.all([
        incidentsApi.getStats(statsParams) as Promise<IncidentStats>,
        incidentsApi.getAll({ per_page: '5' }) as Promise<PaginatedResponse>,
      ]);
      setStats(statsData);
      setRecent(recentData.data);
    } catch (error) {
      void error;
      showToast('error', t('ovr.load_error'));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchData(period, filters);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period, filters]);

  const updateFilter = (key: keyof StatsFilters, value: string) => {
    if (key === 'from' || key === 'to') {
      setPeriod('all');
    }
    setFilters((current) => ({ ...current, [key]: value }));
  };

  const selectPeriod = (selectedPeriod: Period) => {
    setPeriod(selectedPeriod);
    setFilters((current) => {
      if (!current.from && !current.to) {
        return current;
      }

      return { ...current, from: '', to: '' };
    });
  };

  const resetFilters = () => {
    setPeriod('all');
    setFilters(initialFilters);
  };

  const activeFilterCount = Object.values(filters).filter((value) => value !== '').length;

  const statusChartData = Object.entries(stats?.by_status || {})
    .filter(([, value]) => value > 0)
    .map(([key, value]) => ({
      name: statusLabels[key] ? t(statusLabels[key]) : key,
      value,
      color: statusChartColors[key as OvrStatusKey] || 'var(--text-tertiary)',
    }));

  const severityChartData = Object.entries(stats?.by_severity || {})
    .filter(([, value]) => value > 0)
    .map(([key, value]) => ({
      name: severityLabels[key] ? t(severityLabels[key]) : key,
      value,
      color: severityChartColors[key as OvrSeverityKey] || 'var(--text-tertiary)',
    }));

  const statusTotal = statusChartData.reduce((sum, item) => sum + item.value, 0);

  const summaryItems: StatStripItem[] = [
    { label: t('ovr.total_incidents'), value: stats?.total ?? 0, tone: 'neutral' },
    { label: t('ovr.overdue'), value: stats?.overdue ?? 0, tone: 'warning' },
    { label: t('ovr.patient_related'), value: stats?.patient_related ?? 0, tone: 'accent' },
    {
      label: t('ovr.avg_resolution'),
      value: stats?.avg_resolution_hours
        ? `${Math.round(stats.avg_resolution_hours)} ${t('ovr.hours')}`
        : '-',
      tone: 'success',
    },
  ];

  const booleanFilterOptions = [
    { value: '', label: t('common.all') },
    { value: '1', label: t('common.yes') },
    { value: '0', label: t('common.no') },
  ];

  const statusFilterOptions = [
    { value: '', label: t('ovr.all_statuses') },
    ...Object.entries(statusLabels).map(([value, label]) => ({ value, label: t(label) })),
  ];

  const severityFilterOptions = [
    { value: '', label: t('ovr.all_severities') },
    ...Object.entries(severityLabels).map(([value, label]) => ({ value, label: t(label) })),
  ];

  const patientGenderOptions = [
    { value: '', label: t('ovr.all_patient_genders') },
    { value: 'male', label: t('ovr.patient_gender_male') },
    { value: 'female', label: t('ovr.patient_gender_female') },
    { value: 'unspecified', label: t('ovr.patient_gender_unspecified') },
  ];

  const getLocalizedBreakdownName = (row: NamedBreakdownRow) => {
    if (isArabic && row.name_ar) {
      return row.name_ar;
    }

    return row.name || row.name_ar || String(row.id);
  };

  const namedBreakdownOptions = (rows: NamedBreakdownRow[] | undefined, allLabel: string) => [
    { value: '', label: allLabel },
    ...(rows ?? []).map((row) => ({
      value: String(row.id),
      label: getLocalizedBreakdownName(row),
    })),
  ];

  const incidentTypeOptions = namedBreakdownOptions(
    stats?.breakdowns?.incident_type,
    t('ovr.all_incident_types'),
  );
  const reportableTypeOptions = namedBreakdownOptions(
    stats?.breakdowns?.reportable_type,
    t('ovr.all_reportable_types'),
  );
  const departmentOptions = namedBreakdownOptions(
    stats?.breakdowns?.department,
    t('ovr.all_departments'),
  );
  const contributingFactorOptions = [
    { value: '', label: t('ovr.all_contributing_factors') },
    ...Object.entries(contributingFactorLabels).map(([value, label]) => ({ value, label: t(label) })),
  ];

  const formatRate = (value: number | undefined) => `${Number(value ?? 0).toFixed(1)}%`;

  const operationalRates = [
    {
      label: t('ovr.patient_related'),
      count: stats?.patient_related ?? 0,
      rate: stats?.rates?.patient_related,
    },
    {
      label: t('ovr.authority_informed'),
      count: stats?.informed_authority ?? 0,
      rate: stats?.rates?.informed_authority,
    },
    {
      label: t('ovr.immediate_action_required'),
      count: stats?.immediate_action_required ?? 0,
      rate: stats?.rates?.immediate_action_required,
    },
    {
      label: t('ovr.confidential'),
      count: stats?.confidential ?? 0,
      rate: stats?.rates?.confidential,
    },
  ];

  const incidentTypeRows = (stats?.breakdowns?.incident_type ?? []).map((row) => ({
    key: `incident-type-${row.id}`,
    label: getLocalizedBreakdownName(row),
    count: row.count,
  }));
  const reportableTypeRows = (stats?.breakdowns?.reportable_type ?? []).map((row) => ({
    key: `reportable-type-${row.id}`,
    label: getLocalizedBreakdownName(row),
    count: row.count,
  }));
  const departmentRows = (stats?.breakdowns?.department ?? []).map((row) => ({
    key: `department-${row.id}`,
    label: getLocalizedBreakdownName(row),
    count: row.count,
  }));
  const patientGenderRows = (stats?.breakdowns?.patient_gender ?? []).map((row) => ({
    key: `patient-gender-${row.gender}`,
    label: t(`ovr.patient_gender_${row.gender}`),
    count: row.count,
  }));
  const contributingFactorRows = (stats?.breakdowns?.contributing_factor ?? []).map((row) => ({
    key: `factor-${row.factor}`,
    label: contributingFactorLabels[row.factor] ? t(contributingFactorLabels[row.factor]) : row.name || row.factor,
    count: row.count,
  }));
  const monthlyTrendRows = (stats?.breakdowns?.monthly_trend ?? []).map((row) => ({
    key: `month-${row.month}`,
    label: row.month,
    count: row.count,
  }));

  const renderBreakdownCard = (
    title: string,
    rows: Array<{ key: string; label: string; count: number }>,
  ) => {
    const maxCount = Math.max(...rows.map((row) => row.count), 1);

    return (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="text-sm">{title}</CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          {rows.length === 0 ? (
            <p className="py-6 text-center text-sm text-[var(--text-tertiary)]">
              {t('common.no_data')}
            </p>
          ) : (
            <div className="space-y-3">
              {rows.slice(0, 6).map((row) => (
                <div key={row.key} className="space-y-1.5">
                  <div className="flex items-center justify-between gap-3 text-sm">
                    <span className="truncate text-[var(--text-secondary)]">{row.label}</span>
                    <span className="font-medium text-[var(--text-primary)]">{row.count}</span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                    <div
                      className="h-full rounded-full bg-[var(--accent-default)]"
                      style={{ width: `${(row.count / maxCount) * 100}%` }}
                    />
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    );
  };

  const StatusTooltip = ({ active, payload }: any) => {
    if (active && payload && payload.length) {
      const item = payload[0];
      const percentage =
        statusTotal > 0 ? ((item.value / statusTotal) * 100).toFixed(1) : '0';
      return (
        <div className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg shadow-lg p-3">
          <p className="text-sm font-medium text-[var(--text-primary)]">{item.name}</p>
          <p className="text-sm text-[var(--text-secondary)]">
            {item.value} ({percentage}%)
          </p>
        </div>
      );
    }
    return null;
  };

  const SeverityTooltip = ({ active, payload }: any) => {
    if (active && payload && payload.length) {
      const item = payload[0];
      return (
        <div className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg shadow-lg p-3">
          <p className="text-sm font-medium text-[var(--text-primary)]">
            {item.payload.name}
          </p>
          <p className="text-sm text-[var(--text-secondary)]">{item.value}</p>
        </div>
      );
    }
    return null;
  };

  return (
    <div id="main-content" className="space-y-6">
      <SkipToMain label={t('a11y.skip_to_main')} />
      {/* Header */}
      <PageHeader
        title={t('ovr.statisticsTitle')}
        subtitle={t('ovr.subtitle')}
        icon={IconChartBar}
        iconTone="risk"
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div
              role="group"
              aria-label={t('ovr.dashboard.period_label')}
              className="flex flex-wrap items-center gap-2 rounded-xl bg-[var(--surface-muted)] p-1"
            >
              {periodOptions.map((option) => (
                <Button
                  key={option}
                  size="sm"
                  variant={period === option ? 'primary' : 'ghost'}
                  onClick={() => selectPeriod(option)}
                  aria-pressed={period === option}
                >
                  {t(`ovr.period_${option}`)}
                </Button>
              ))}
            </div>
            <FilterButton
              isOpen={isFiltersOpen}
              onClick={() => setIsFiltersOpen((current) => !current)}
              activeCount={activeFilterCount}
            />
          </div>
        }
      />

      {/* Filters */}
      {isFiltersOpen && (
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="text-sm">{t('ovr.analytics_filters')}</CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <DatePicker
              label={t('common.from')}
              value={filters.from}
              onChange={(value) => updateFilter('from', value)}
              placeholder={t('ovr.from_date')}
            />
            <DatePicker
              label={t('common.to')}
              value={filters.to}
              onChange={(value) => updateFilter('to', value)}
              placeholder={t('ovr.to_date')}
            />
            <Select
              label={t('common.status')}
              options={statusFilterOptions}
              value={filters.status}
              onChange={(event) => updateFilter('status', event.target.value)}
            />
            <Select
              label={t('ovr.severity')}
              options={severityFilterOptions}
              value={filters.severity}
              onChange={(event) => updateFilter('severity', event.target.value)}
            />
            <Select
              label={t('ovr.incident_type')}
              options={incidentTypeOptions}
              value={filters.incident_type_id}
              onChange={(event) => updateFilter('incident_type_id', event.target.value)}
              searchable
            />
            <Select
              label={t('ovr.reportable_type')}
              options={reportableTypeOptions}
              value={filters.reportable_incident_type_id}
              onChange={(event) => updateFilter('reportable_incident_type_id', event.target.value)}
              searchable
            />
            <Select
              label={t('common.department')}
              options={departmentOptions}
              value={filters.reporter_department_id}
              onChange={(event) => updateFilter('reporter_department_id', event.target.value)}
              searchable
            />
            <Select
              label={t('ovr.contributing_factors')}
              options={contributingFactorOptions}
              value={filters.contributing_factor}
              onChange={(event) => updateFilter('contributing_factor', event.target.value)}
              searchable
            />
            <Select
              label={t('ovr.patient_related')}
              options={booleanFilterOptions}
              value={filters.is_patient_related}
              onChange={(event) => updateFilter('is_patient_related', event.target.value)}
            />
            <Select
              label={t('ovr.patient_gender')}
              options={patientGenderOptions}
              value={filters.patient_gender}
              onChange={(event) => updateFilter('patient_gender', event.target.value)}
            />
            <Select
              label={t('ovr.authority_informed')}
              options={booleanFilterOptions}
              value={filters.informed_authority}
              onChange={(event) => updateFilter('informed_authority', event.target.value)}
            />
            <Select
              label={t('ovr.immediate_action_required')}
              options={booleanFilterOptions}
              value={filters.immediate_action_required}
              onChange={(event) => updateFilter('immediate_action_required', event.target.value)}
            />
            <Select
              label={t('ovr.confidential')}
              options={booleanFilterOptions}
              value={filters.is_confidential}
              onChange={(event) => updateFilter('is_confidential', event.target.value)}
            />
            <div className="flex items-end">
              <Button type="button" variant="outline" className="w-full" onClick={resetFilters}>
                {t('common.clear_filters')}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
      )}

      {/* Summary stat strip */}
      {isLoading ? (
        <Skeleton role="status" aria-label={t('common.loading')} className="h-20 w-full rounded-lg" />
      ) : (
        <StatStrip items={summaryItems} />
      )}

      {/* Operational indicators */}
      {isLoading ? (
        <Skeleton role="status" aria-label={t('common.loading')} className="h-28 w-full rounded-lg" />
      ) : (
        <Card>
          <CardHeader className="border-b border-[var(--border-default)] pb-3">
            <CardTitle className="text-sm">{t('ovr.operational_breakdown')}</CardTitle>
          </CardHeader>
          <CardContent className="p-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {operationalRates.map((item) => (
                <div
                  key={item.label}
                  className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-muted)] p-3"
                >
                  <p className="text-xs text-[var(--text-tertiary)]">{item.label}</p>
                  <div className="mt-2 flex items-end justify-between gap-3">
                    <span className="text-xl font-semibold text-[var(--text-primary)]">
                      {item.count}
                    </span>
                    <span className="text-sm font-medium text-[var(--accent-default)]">
                      {formatRate(item.rate)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Charts */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Status donut */}
        <Card>
          <CardHeader className="border-b border-[var(--border-default)] pb-3">
            <CardTitle className="flex items-center gap-2 text-sm">
              <div className="h-8 w-8 rounded-lg bg-[var(--support-amber-subtle)] flex items-center justify-center">
                <IconChartPie className="h-4 w-4 text-[var(--support-amber-text)]" />
              </div>
              {t('ovr.incidents_by_status')}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4">
            {isLoading ? (
              <div className="h-[220px] flex items-center justify-center">
                <div className="animate-pulse w-40 h-40 rounded-full bg-[var(--surface-muted)]" />
              </div>
            ) : statusChartData.length === 0 ? (
              <div className="h-[220px] flex flex-col items-center justify-center text-[var(--text-tertiary)]">
                <IconChartPie className="h-12 w-12 mb-2 opacity-50" />
                <p className="text-sm">{t('ovr.no_incidents')}</p>
              </div>
            ) : (
              <>
                <div className="h-[220px]">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={statusChartData}
                        cx="50%"
                        cy="50%"
                        innerRadius={50}
                        outerRadius={80}
                        paddingAngle={2}
                        dataKey="value"
                      >
                        {statusChartData.map((entry, index) => (
                          <Cell key={`status-cell-${index}`} fill={entry.color} stroke="none" />
                        ))}
                      </Pie>
                      <Tooltip content={<StatusTooltip />} />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
                <div className="text-center -mt-4 mb-2">
                  <p className="text-2xl font-bold text-[var(--text-primary)]">{statusTotal}</p>
                  <p className="text-xs text-[var(--text-tertiary)]">
                    {t('ovr.total_incidents')}
                  </p>
                </div>
                <div className="flex flex-wrap justify-center gap-3 mt-2">
                  {statusChartData.map((entry) => (
                    <div key={entry.name} className="flex items-center gap-1">
                      <div
                        className="w-3 h-3 rounded-full"
                        style={{ backgroundColor: entry.color }}
                      />
                      <span className="text-xs text-[var(--text-secondary)]">
                        {entry.name}: {entry.value}
                      </span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* Severity bar chart */}
        <Card>
          <CardHeader className="border-b border-[var(--border-default)] pb-3">
            <CardTitle className="flex items-center gap-2 text-sm">
              <div className="h-8 w-8 rounded-lg bg-[var(--support-amber-subtle)] flex items-center justify-center">
                <IconChartBar className="h-4 w-4 text-[var(--support-amber-text)]" />
              </div>
              {t('ovr.incidents_by_severity')}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4">
            {isLoading ? (
              <div className="h-[220px] flex items-end justify-center gap-4">
                {[...Array(4)].map((_, i) => (
                  <Skeleton
                    key={i}
                    className="w-12 rounded-t-lg"
                    style={{ height: `${60 + i * 30}px` }}
                  />
                ))}
              </div>
            ) : severityChartData.length === 0 ? (
              <div className="h-[220px] flex flex-col items-center justify-center text-[var(--text-tertiary)]">
                <IconChartBar className="h-12 w-12 mb-2 opacity-50" />
                <p className="text-sm">{t('ovr.no_incidents')}</p>
              </div>
            ) : (
              <div className="h-[220px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={severityChartData} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border-default)" vertical={false} />
                    <XAxis
                      dataKey="name"
                      tick={{ fontSize: 12, fill: 'var(--text-secondary)' }}
                      axisLine={{ stroke: 'var(--border-default)' }}
                      tickLine={false}
                    />
                    <YAxis
                      allowDecimals={false}
                      tick={{ fontSize: 12, fill: 'var(--text-secondary)' }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <Tooltip content={<SeverityTooltip />} cursor={{ fill: 'var(--surface-muted)' }} />
                    <Bar dataKey="value" radius={[6, 6, 0, 0]}>
                      {severityChartData.map((entry, index) => (
                        <Cell key={`severity-cell-${index}`} fill={entry.color} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Detailed breakdowns */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {renderBreakdownCard(t('ovr.breakdown_incident_type'), incidentTypeRows)}
        {renderBreakdownCard(t('ovr.breakdown_reportable_type'), reportableTypeRows)}
        {renderBreakdownCard(t('ovr.breakdown_department'), departmentRows)}
        {renderBreakdownCard(t('ovr.breakdown_patient_gender'), patientGenderRows)}
        {renderBreakdownCard(t('ovr.breakdown_contributing_factor'), contributingFactorRows)}
        {renderBreakdownCard(t('ovr.breakdown_monthly_trend'), monthlyTrendRows)}
      </div>

      {/* Recent incidents */}
      <Card>
        <CardHeader className="border-b border-[var(--border-default)] pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <div className="h-8 w-8 rounded-lg bg-[var(--support-amber-subtle)] flex items-center justify-center">
              <IconAlertTriangle className="h-4 w-4 text-[var(--support-amber-text)]" />
            </div>
            {t('ovr.recent_incidents')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-4">
          {isLoading ? (
            <div className="space-y-4">
              {[...Array(5)].map((_, i) => (
                <div key={i} className="flex items-center gap-4">
                  <Skeleton className="h-10 w-10 rounded-lg" />
                  <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-48" />
                    <Skeleton className="h-3 w-32" />
                  </div>
                </div>
              ))}
            </div>
          ) : recent.length === 0 ? (
            <div className="text-center py-10 text-[var(--text-tertiary)]">
              <IconAlertTriangle className="h-12 w-12 mx-auto mb-3 opacity-50" />
              <p className="text-sm">{t('ovr.no_incidents')}</p>
            </div>
          ) : (
            <ul className="divide-y divide-[var(--border-default)]">
              {recent.map((incident) => (
                <li
                  key={incident.id}
                  className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                >
                  <div className="min-w-0">
                    <p className="font-mono font-medium text-[var(--text-primary)]">
                      {incident.report_number}
                    </p>
                    <p className="text-sm text-[var(--text-secondary)] truncate max-w-[200px] sm:max-w-md">
                      {incident.description || incident.incident_type?.name || '-'}
                    </p>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Badge variant="default" size="sm">
                      {t(statusLabels[incident.status])}
                    </Badge>
                    <span className="hidden sm:flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
                      <IconClock className="h-3 w-3" />
                      {formatDate(incident.incident_datetime || incident.created_at)}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default OVRDashboard;
