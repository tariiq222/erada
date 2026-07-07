import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconCheck, IconChevronLeft } from '@tabler/icons-react';
import { projectsApi } from '@entities/project';
import { performanceApi } from '@entities/performance';
import type { PerformanceKPI } from '@entities/performance';
import { useToast } from '@shared/ui/Toast';
import { Card } from '@shared/ui';
import CheckMeasurementModal, { type CheckMeasurement } from './CheckMeasurementModal';
import type { ProjectDetails } from '../../types';

const PHASES = ['plan', 'do', 'check', 'act'] as const;
type Phase = (typeof PHASES)[number];

const NEXT: Record<Phase, Phase> = {
  plan: 'do',
  do: 'check',
  check: 'act',
  act: 'plan',
};

interface PdcaStepperProps {
  project: ProjectDetails;
  canManage?: boolean;
  onChanged?: () => void;
}

const PdcaStepper: React.FC<PdcaStepperProps> = ({ project, canManage = true, onChanged }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const current = (project.current_pdca_phase ?? 'plan') as Phase;
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [kpis, setKpis] = useState<PerformanceKPI[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const target = NEXT[current];

  const submitPhase = async (phase: Phase, measurements?: CheckMeasurement[]) => {
    setIsSubmitting(true);
    try {
      await projectsApi.updatePdcaPhase(project.id, { phase, ...(measurements ? { measurements } : {}) });
      showToast('success', t('projects.pdca_phase_updated'));
      setIsModalOpen(false);
      onChanged?.();
    } catch {
      showToast('error', t('projects.pdca_phase_update_failed'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleAdvance = async () => {
    if (!canManage) return;
    if (target === 'check') {
      try {
        const response = await performanceApi.listContextKPIs('project', project.id);
        setKpis(response.data);
        setIsModalOpen(true);
      } catch {
        showToast('error', t('projects.pdca_phase_update_failed'));
      }
      return;
    }
    await submitPhase(target);
  };

  const advanceLabel = target === 'plan'
    ? t('projects.pdca_restart_cycle')
    : `${t('projects.pdca_advance_to')} ${t(`projects.pdca_${target}`)}`;

  return (
    <Card className="p-4 space-y-4 border border-[var(--border-default)]">
      <div className="flex items-center justify-between">
        <h3 className="font-semibold text-[var(--text-primary)]">{t('projects.pdca_cycle_title')}</h3>
      </div>

      <ol className="flex items-center gap-2">
        {PHASES.map((phase, index) => {
          const isCurrent = phase === current;
          const currentIndex = PHASES.indexOf(current);
          const isDone = index < currentIndex;
          return (
            <li key={phase} className="flex flex-1 items-center gap-2">
              <div
                className={[
                  'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-medium',
                  isCurrent
                    ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                    : isDone
                      ? 'bg-[var(--status-success)] text-[var(--text-inverse)]'
                      : 'bg-[var(--surface-muted)] text-[var(--text-tertiary)]',
                ].join(' ')}
              >
                {isDone ? <IconCheck className="h-4 w-4" /> : index + 1}
              </div>
              <span
                className={[
                  'text-sm',
                  isCurrent ? 'font-semibold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]',
                ].join(' ')}
              >
                {t(`projects.pdca_${phase}`)}
              </span>
              {index < PHASES.length - 1 && (
                <div className="mx-1 h-px flex-1 bg-[var(--border-default)]" aria-hidden />
              )}
            </li>
          );
        })}
      </ol>

      {canManage && (
        <div className="flex justify-end">
          <button
            onClick={handleAdvance}
            disabled={isSubmitting}
            className="inline-flex items-center gap-1 rounded-lg border border-[var(--border-default)] px-3 py-1.5 text-sm text-[var(--text-primary)] hover:bg-[var(--surface-muted)] disabled:opacity-50"
          >
            <IconChevronLeft className="h-4 w-4" />
            {advanceLabel}
          </button>
        </div>
      )}

      <CheckMeasurementModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        kpis={kpis}
        isSubmitting={isSubmitting}
        onConfirm={(measurements) => submitPhase('check', measurements)}
      />
    </Card>
  );
};

export default PdcaStepper;
