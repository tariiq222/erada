import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { IconPlus, IconTrash } from '@tabler/icons-react';
import { Input } from '@shared/ui/Input';
import type { KpiInput } from './types';

interface KpiRepeaterProps {
  kpis: KpiInput[];
  errors?: Record<string, string[]>;
  onKpiChange: (index: number, field: keyof KpiInput, value: string) => void;
  onAddKpi: () => void;
  onRemoveKpi: (index: number) => void;
  compact?: boolean;
}

const KpiRepeater = memo<KpiRepeaterProps>(({ kpis, errors, onKpiChange, onAddKpi, onRemoveKpi, compact = false }) => {
  const { t } = useTranslation();

  return (
    <div className={compact ? 'space-y-3' : 'space-y-4'}>
      <div className="flex items-center justify-between gap-3">
        <p className="text-xs text-[var(--text-tertiary)]">{t('projects.improvement_kpi_required_hint')}</p>
        <button
          type="button"
          onClick={onAddKpi}
          className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-[var(--border-default)] px-2.5 py-1 text-sm text-[var(--text-primary)] hover:bg-[var(--surface-muted)]"
        >
          <IconPlus className="h-4 w-4" />
          {t('projects.add_kpi')}
        </button>
      </div>

      <div className="space-y-3">
        {kpis.map((kpi, index) => (
          <div key={index} className="rounded-lg border border-[var(--border-default)] p-3 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-[var(--text-secondary)]">
                {t('projects.kpi_indicator')} {index + 1}
              </span>
              {kpis.length > 1 && (
                <button
                  type="button"
                  onClick={() => onRemoveKpi(index)}
                  className="p-1 rounded-lg text-[var(--text-tertiary)] hover:text-[var(--status-danger)] hover:bg-[var(--surface-muted)]"
                  aria-label={t('common.delete')}
                >
                  <IconTrash className="h-4 w-4" />
                </button>
              )}
            </div>
            <Input
              label={t('projects.kpi_indicator')}
              value={kpi.name}
              onChange={(e) => onKpiChange(index, 'name', e.target.value)}
              error={errors?.[`kpis.${index}.name`]?.[0]}
            />
            <div className="grid grid-cols-2 gap-3">
              <Input
                type="number"
                label={t('projects.kpi_baseline')}
                value={kpi.baseline}
                onChange={(e) => onKpiChange(index, 'baseline', e.target.value)}
                error={errors?.[`kpis.${index}.baseline`]?.[0]}
              />
              <Input
                type="number"
                label={t('projects.kpi_target')}
                value={kpi.target}
                onChange={(e) => onKpiChange(index, 'target', e.target.value)}
                error={errors?.[`kpis.${index}.target`]?.[0]}
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Input
                label={t('projects.kpi_unit')}
                value={kpi.unit}
                onChange={(e) => onKpiChange(index, 'unit', e.target.value)}
              />
              <Input
                label={t('projects.kpi_measurement_method')}
                value={kpi.measurement_method}
                onChange={(e) => onKpiChange(index, 'measurement_method', e.target.value)}
              />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
});

KpiRepeater.displayName = 'KpiRepeater';

export default KpiRepeater;
