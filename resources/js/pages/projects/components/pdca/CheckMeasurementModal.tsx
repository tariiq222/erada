import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal, ModalBody, ModalFooter, Button, Input } from '@shared/ui';
import type { PerformanceKPI } from '@entities/performance';

export interface CheckMeasurement {
  kpi_id: number;
  value: number;
  measurement_date: string;
}

interface CheckMeasurementModalProps {
  isOpen: boolean;
  onClose: () => void;
  kpis: PerformanceKPI[];
  isSubmitting?: boolean;
  onConfirm: (measurements: CheckMeasurement[]) => void;
}

const today = () => new Date().toISOString().slice(0, 10);

const CheckMeasurementModal: React.FC<CheckMeasurementModalProps> = ({
  isOpen,
  onClose,
  kpis,
  isSubmitting = false,
  onConfirm,
}) => {
  const { t } = useTranslation();
  const [values, setValues] = useState<Record<number, string>>({});
  const [dates, setDates] = useState<Record<number, string>>({});

  const allFilled = useMemo(
    () => kpis.length > 0 && kpis.every((kpi) => values[kpi.id] !== undefined && values[kpi.id] !== ''),
    [kpis, values],
  );

  const handleConfirm = () => {
    if (!allFilled) return;
    onConfirm(
      kpis.map((kpi) => ({
        kpi_id: kpi.id,
        value: Number(values[kpi.id]),
        measurement_date: dates[kpi.id] ?? today(),
      })),
    );
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('projects.pdca_check_measure_title')} size="lg">
      <ModalBody>
        <p className="text-sm text-[var(--text-secondary)] mb-4">{t('projects.pdca_check_measure_hint')}</p>
        <div className="space-y-4">
          {kpis.map((kpi) => (
            <div
              key={kpi.id}
              className="rounded-lg border border-[var(--border-default)] p-4 space-y-3"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium text-[var(--text-primary)]">{kpi.name}</span>
                <span className="text-xs text-[var(--text-tertiary)]">
                  {t('projects.kpi_target')}: {kpi.target ?? '—'}
                  {kpi.baseline != null ? ` · ${t('projects.kpi_baseline')}: ${kpi.baseline}` : ''}
                </span>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Input
                  type="number"
                  label={t('projects.kpi_measured_value')}
                  value={values[kpi.id] ?? ''}
                  onChange={(e) => setValues((prev) => ({ ...prev, [kpi.id]: e.target.value }))}
                />
                <Input
                  type="date"
                  label={t('projects.kpi_measurement_date')}
                  value={dates[kpi.id] ?? today()}
                  onChange={(e) => setDates((prev) => ({ ...prev, [kpi.id]: e.target.value }))}
                />
              </div>
            </div>
          ))}
        </div>
      </ModalBody>
      <ModalFooter>
        <Button variant="outline" onClick={onClose} disabled={isSubmitting}>
          {t('common.cancel')}
        </Button>
        <Button onClick={handleConfirm} disabled={!allFilled || isSubmitting} loading={isSubmitting}>
          {t('projects.pdca_check_confirm')}
        </Button>
      </ModalFooter>
    </Modal>
  );
};

export default CheckMeasurementModal;
