import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import {IconAlertTriangle, IconPlus, IconTrash} from '@tabler/icons-react';
import { impactOptions, probabilityOptions } from './constants';
import type { RiskItem } from './types';

interface RisksSectionProps {
  risks: RiskItem[];
  onRiskChange: (index: number, field: keyof RiskItem, value: string) => void;
  onAddRisk: () => void;
  onRemoveRisk: (index: number) => void;
  compact?: boolean;
}

const RisksSection = memo<RisksSectionProps>(({
  risks,
  onRiskChange,
  onAddRisk,
  onRemoveRisk,
  compact = false,
}) => {
  const { t } = useTranslation();

  const translatedImpactOptions = impactOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));
  const translatedProbabilityOptions = probabilityOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));

  if (compact) {
    return (
      <div className="space-y-3">
        <div className="flex justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onAddRisk}
            leftIcon={<IconPlus className="h-3.5 w-3.5" />}
            className="h-11 w-full sm:w-auto lg:h-8"
          >
            {t('projects.add_risk')}
          </Button>
        </div>

        <div className="grid grid-cols-1 gap-3">
          {risks.map((risk, index) => {
            const descriptionId = `project-risk-compact-${index}-description`;
            const impactId = `project-risk-compact-${index}-impact`;
            const probabilityId = `project-risk-compact-${index}-probability`;
            const mitigationId = `project-risk-compact-${index}-mitigation`;

            return (
              <div key={index} className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-2">
                <div className="flex items-center justify-between gap-2 mb-2">
                  <span className="text-xs font-medium text-[var(--text-primary)] flex items-center gap-1">
                    <IconAlertTriangle className="h-3.5 w-3.5 text-[var(--support-amber-text)]" />
                    {t('projects.risk_number', { number: index + 1 })}
                  </span>
                  {risks.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="2xs"
                      onClick={() => onRemoveRisk(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                    >
                      <IconTrash className="h-3 w-3" />
                    </IconButton>
                  )}
                </div>
                <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                  <div className="md:col-span-2">
                    <label htmlFor={descriptionId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.risk_description')}</label>
                    <Input
                      id={descriptionId}
                      value={risk.description}
                      onChange={(e) => onRiskChange(index, 'description', e.target.value)}
                      placeholder={t('projects.enter_risk_description')}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                  <div>
                    <label htmlFor={impactId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.impact')}</label>
                    <Select
                      id={impactId}
                      value={risk.impact}
                      onChange={(e) => onRiskChange(index, 'impact', e.target.value)}
                      options={translatedImpactOptions}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                  <div>
                    <label htmlFor={probabilityId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.probability')}</label>
                    <Select
                      id={probabilityId}
                      value={risk.probability}
                      onChange={(e) => onRiskChange(index, 'probability', e.target.value)}
                      options={translatedProbabilityOptions}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                  <div className="md:col-span-2">
                    <label htmlFor={mitigationId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.mitigation_plan')}</label>
                    <Input
                      id={mitigationId}
                      value={risk.mitigation}
                      onChange={(e) => onRiskChange(index, 'mitigation', e.target.value)}
                      placeholder={t('projects.enter_mitigation_plan')}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconAlertTriangle className="h-5 w-5 text-[var(--support-amber-text)]" />
          {t('projects.risks')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        <div className="grid grid-cols-1 gap-3">
          {risks.map((risk, index) => {
            const descriptionId = `project-risk-${index}-description`;
            const impactId = `project-risk-${index}-impact`;
            const probabilityId = `project-risk-${index}-probability`;
            const mitigationId = `project-risk-${index}-mitigation`;

            return (
              <div key={index} className="p-3 border border-[var(--border-default)] rounded-lg bg-[var(--surface-base)]">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm font-medium text-[var(--text-primary)] flex items-center gap-1">
                    <IconAlertTriangle className="h-3.5 w-3.5 text-[var(--support-amber-text)]" />
                    {t('projects.risk_number', { number: index + 1 })}
                  </span>
                  {risks.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="2xs"
                      onClick={() => onRemoveRisk(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                    >
                      <IconTrash className="h-3 w-3" />
                    </IconButton>
                  )}
                </div>
                <div className="space-y-3">
                  <div>
                    <label htmlFor={descriptionId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.risk_description')}</label>
                    <Input
                      id={descriptionId}
                      value={risk.description}
                      onChange={(e) => onRiskChange(index, 'description', e.target.value)}
                      placeholder={t('projects.enter_risk_description')}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <label htmlFor={impactId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.impact')}</label>
                      <Select
                        id={impactId}
                        value={risk.impact}
                        onChange={(e) => onRiskChange(index, 'impact', e.target.value)}
                        options={translatedImpactOptions}
                        className="min-h-11 text-sm lg:min-h-9"
                      />
                    </div>
                    <div>
                      <label htmlFor={probabilityId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.probability')}</label>
                      <Select
                        id={probabilityId}
                        value={risk.probability}
                        onChange={(e) => onRiskChange(index, 'probability', e.target.value)}
                        options={translatedProbabilityOptions}
                        className="min-h-11 text-sm lg:min-h-9"
                      />
                    </div>
                  </div>
                  <div>
                    <label htmlFor={mitigationId} className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('projects.mitigation_plan')}</label>
                    <Input
                      id={mitigationId}
                      value={risk.mitigation}
                      onChange={(e) => onRiskChange(index, 'mitigation', e.target.value)}
                      placeholder={t('projects.enter_mitigation_plan')}
                      className="min-h-11 text-sm lg:min-h-9"
                    />
                  </div>
                </div>
              </div>
            );
          })}
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onAddRisk}
          leftIcon={<IconPlus className="h-4 w-4" />}
          className="mt-4 h-11 lg:h-8"
        >
          {t('projects.add_risk')}
        </Button>
      </CardContent>
    </Card>
  );
});

RisksSection.displayName = 'RisksSection';

export default RisksSection;
