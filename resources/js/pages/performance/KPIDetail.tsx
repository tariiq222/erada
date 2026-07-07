import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconAlertTriangle, IconCalendar, IconDeviceFloppy, IconPencil, IconPlus, IconTarget, IconTrash, IconTrendingUp} from '@tabler/icons-react';
import { performanceApi, type PerformanceKPI } from '@entities/performance';
import { useCan } from '@shared/api/access';
import {
  Breadcrumb,
  Button,
  Card,
  CardContent,
  DeleteConfirmationModal,
  DatePicker,
  FormSection,
  Input,
  PageHeader,
  Progress,
  StatCard,
  StatusBadge,
  Textarea,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {
  achievement,
  displayValue,
  formatDate,
  getErrorMessage,
  performanceColor,
  performanceLabelKey,
  statusColor,
  statusLabelKey,
  todayInputValue,
} from './shared';

const KPIDetail: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  // Phase 9.3 freeze cleanup (2026-07-06): gate on the canonical `kpis.*`
  // namespace. Granular `kpis.edit/delete` win; `kpis.manage` is the
  // umbrella fallback for roles granted only the bundle capability.
  const canEditKpi = useCan('kpis.edit');
  const canDeleteKpi = useCan('kpis.delete');
  const canManageKpi = useCan('kpis.manage');
  const canEdit = canEditKpi || canManageKpi;
  const canDelete = canDeleteKpi || canManageKpi;
  const [kpi, setKpi] = useState<PerformanceKPI | null>(null);
  const [loading, setLoading] = useState(true);
  const [savingMeasurement, setSavingMeasurement] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [measurementForm, setMeasurementForm] = useState({
    value: '',
    measurement_date: todayInputValue(),
    notes: '',
    evidence_url: '',
  });

  const kpiId = Number(id);

  const fetchKpi = async () => {
    setLoading(true);
    try {
      const response = await performanceApi.getKPI(kpiId);
      setKpi(response);
    } catch (error) {
      console.error('Failed to fetch performance KPI:', error);
      showToast('error', t('performance.load_error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!Number.isFinite(kpiId)) {
      navigate('/performance/kpis');
      return;
    }
    fetchKpi();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const handleMeasurementSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!measurementForm.value || !measurementForm.measurement_date) {
      showToast('error', t('performance.measurement_required'));
      return;
    }

    setSavingMeasurement(true);
    try {
      await performanceApi.createMeasurement(kpiId, {
        value: measurementForm.value,
        measurement_date: measurementForm.measurement_date,
        ...(measurementForm.notes.trim() ? { notes: measurementForm.notes.trim() } : {}),
        ...(measurementForm.evidence_url.trim() ? { evidence_url: measurementForm.evidence_url.trim() } : {}),
      });
      showToast('success', t('performance.measurement_create_success'));
      setMeasurementForm({ value: '', measurement_date: todayInputValue(), notes: '', evidence_url: '' });
      await fetchKpi();
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.measurement_create_error')));
    } finally {
      setSavingMeasurement(false);
    }
  };

  const handleDelete = async () => {
    if (!kpi) return;
    setIsDeleting(true);
    try {
      await performanceApi.deleteKPI(kpi.id);
      showToast('success', t('performance.delete_success'));
      navigate('/performance/kpis');
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.delete_error')));
    } finally {
      setIsDeleting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--border-default)] border-t-[var(--accent-default)]" />
      </div>
    );
  }

  if (!kpi) {
    return (
      <div className="py-12 text-center">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">{t('performance.not_found')}</h2>
        <Link to="/performance/kpis" className="mt-2 inline-block text-[var(--accent-default)] hover:underline">
          {t('performance.back_to_kpis')}
        </Link>
      </div>
    );
  }

  const progress = achievement(kpi);

  return (
    <div className="space-y-5">
      <Breadcrumb
        items={[
          { label: t('performance.kpis'), href: '/performance/kpis' },
          { label: kpi.name },
        ]}
      />

      <PageHeader
        title={kpi.name}
        subtitle={kpi.code || t('performance.detail_subtitle')}
        icon={IconTarget}
        iconTone="project"
        actions={
          <>
            <StatusBadge
              type="custom"
              status={kpi.status || 'active'}
              color={statusColor(kpi.status)}
              label={t(statusLabelKey(kpi.status))}
              size="sm"
            />
            <StatusBadge
              type="custom"
              status={kpi.performance_status || 'unknown'}
              color={performanceColor(kpi.performance_status)}
              label={t(performanceLabelKey(kpi.performance_status))}
              size="sm"
            />
            {canEdit && (
              <Link to={`/performance/kpis/${kpi.id}/edit`}>
                <Button variant="outline" size="sm" leftIcon={<IconPencil className="h-4 w-4" />}>
                  {t('common.edit')}
                </Button>
              </Link>
            )}
            {canDelete && (
              <Button variant="danger" size="sm" leftIcon={<IconTrash className="h-4 w-4" />} onClick={() => setDeleteOpen(true)}>
                {t('common.delete')}
              </Button>
            )}
          </>
        }
      />

      <div className="grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4">
        <StatCard label={t('performance.current_value')} value={displayValue(kpi.current_value, kpi.unit)} icon={IconTrendingUp} color="accent" />
        <StatCard label={t('common.target')} value={displayValue(kpi.target, kpi.unit)} icon={IconTarget} color="success" />
        <StatCard label={t('performance.achievement')} value={`${progress}%`} icon={IconTrendingUp} color="info" />
        <StatCard label={t('performance.measurements')} value={kpi.measurements?.length ?? 0} icon={IconCalendar} color="warning" />
      </div>

      <Card>
        <CardContent className="space-y-4 p-5">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 className="font-semibold text-[var(--text-primary)]">{t('performance.progress_overview')}</h3>
              <p className="text-sm text-[var(--text-tertiary)]">{kpi.direction_label || t(performanceLabelKey(kpi.performance_status))}</p>
            </div>
            <span className="text-2xl font-bold tabular-nums text-[var(--text-primary)]">{progress}%</span>
          </div>
          <Progress value={progress} size="md" />
          <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <InfoItem label={t('performance.baseline')} value={displayValue(kpi.baseline, kpi.unit)} />
            <InfoItem label={t('performance.frequency')} value={kpi.frequency_label || kpi.frequency || '-'} />
            <InfoItem label={t('performance.category')} value={kpi.category || '-'} />
            <InfoItem label={t('common.owner')} value={kpi.owner?.name || '-'} />
          </div>
          {(kpi.description || kpi.measurement_method) && (
            <div className="grid gap-3 text-sm lg:grid-cols-2">
              <InfoItem label={t('common.description')} value={kpi.description || '-'} />
              <InfoItem label={t('performance.measurement_method')} value={kpi.measurement_method || '-'} />
            </div>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
        <Card className="lg:col-span-2">
          <CardContent className="space-y-4 p-5">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h3 className="font-semibold text-[var(--text-primary)]">{t('performance.measurements')}</h3>
                <p className="text-sm text-[var(--text-tertiary)]">{t('performance.measurements_desc')}</p>
              </div>
              <IconPlus className="h-5 w-5 text-[var(--text-tertiary)]" />
            </div>

            {kpi.measurements && kpi.measurements.length > 0 ? (
              <div className="divide-y divide-[var(--border-default)] overflow-hidden rounded-lg border border-[var(--border-default)]">
                {kpi.measurements.map((measurement) => (
                  <div key={measurement.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="font-medium text-[var(--text-primary)]">{displayValue(measurement.value, kpi.unit)}</p>
                      <p className="text-xs text-[var(--text-tertiary)]">{formatDate(measurement.measurement_date)}</p>
                      {measurement.notes && <p className="mt-1 text-sm text-[var(--text-secondary)]">{measurement.notes}</p>}
                    </div>
                    {measurement.recorder && (
                      <span className="text-xs text-[var(--text-tertiary)]">{measurement.recorder.name}</span>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <div className="rounded-lg border border-dashed border-[var(--border-default)] p-8 text-center">
                <IconCalendar className="mx-auto mb-3 h-10 w-10 text-[var(--text-tertiary)]" />
                <p className="font-medium text-[var(--text-primary)]">{t('performance.no_measurements')}</p>
                <p className="text-sm text-[var(--text-tertiary)]">{t('performance.no_measurements_desc')}</p>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="p-5">
          <form onSubmit={handleMeasurementSubmit} className="space-y-5">
            <FormSection title={t('performance.add_measurement')}>
              <Input
                label={t('common.value')}
                type="number"
                step="0.01"
                value={measurementForm.value}
                onChange={(event) => setMeasurementForm((current) => ({ ...current, value: event.target.value }))}
                required
              />
              <DatePicker
                label={t('common.date')}
                value={measurementForm.measurement_date}
                onChange={(value) => setMeasurementForm((current) => ({ ...current, measurement_date: value }))}
                required
              />
              <Textarea
                label={t('common.notes')}
                value={measurementForm.notes}
                onChange={(event) => setMeasurementForm((current) => ({ ...current, notes: event.target.value }))}
                rows={3}
              />
              <Input
                label={t('performance.evidence_url')}
                type="url"
                value={measurementForm.evidence_url}
                onChange={(event) => setMeasurementForm((current) => ({ ...current, evidence_url: event.target.value }))}
                placeholder="https://"
              />
            </FormSection>
            <Button
              type="submit"
              loading={savingMeasurement}
              leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
              className="w-full"
              disabled={!canEdit}
            >
              {t('performance.save_measurement')}
            </Button>
            {!canEdit && (
              <p className="flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
                <IconAlertTriangle className="h-3.5 w-3.5" />
                {t('performance.measurement_permission_hint')}
              </p>
            )}
          </form>
        </Card>
      </div>

      {kpi.links && kpi.links.length > 0 && (
        <Card>
          <CardContent className="space-y-3 p-5">
            <h3 className="font-semibold text-[var(--text-primary)]">{t('performance.links')}</h3>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {kpi.links.map((link) => (
                <div key={link.id} className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-muted)] p-3">
                  <p className="font-medium text-[var(--text-primary)]">
                    {link.linkable?.name || link.linkable?.title || link.linkable?.code || link.linkable_id}
                  </p>
                  <p className="text-xs text-[var(--text-tertiary)]">{link.relationship_type || '-'}</p>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <DeleteConfirmationModal
        isOpen={deleteOpen}
        item={kpi}
        title={t('performance.delete_title')}
        itemName={kpi.name}
        itemSubtitle={kpi.code || undefined}
        warningMessage={t('performance.delete_warning')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
};

const InfoItem: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
  <div className="rounded-lg bg-[var(--surface-muted)] p-3">
    <p className="text-xs font-medium text-[var(--text-tertiary)]">{label}</p>
    <div className="mt-1 text-sm text-[var(--text-primary)]">{value}</div>
  </div>
);

export default KPIDetail;
