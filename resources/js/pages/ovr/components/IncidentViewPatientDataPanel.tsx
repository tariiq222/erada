import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconStethoscope } from '@tabler/icons-react';
import { MaskedField } from '@shared/ui';
import PatientRevealToggle from '@pages/ovr/PatientRevealToggle';
import type { Incident } from './types';

interface PatientDataPanelProps {
  incident: Incident;
}

/**
 * Renders the patient-data block (name + file number) with each value
 * masked by default. A single reveal toggle controls every patient field
 * via shared parent state, so the user only needs one click to expose or
 * hide the full patient-data block.
 */
const PatientDataPanel: React.FC<PatientDataPanelProps> = ({ incident }) => {
  const { t } = useTranslation();
  const [revealed, setRevealed] = useState<boolean>(false);

  if (!incident.is_patient_related) return null;

  return (
    <div className="p-3 bg-[var(--accent-subtle)] rounded-lg border border-[var(--accent-muted)]">
      <div className="flex items-center justify-between gap-2 mb-3">
        <div className="flex items-center gap-2">
          <IconStethoscope className="h-4 w-4 text-[var(--accent-default)]" aria-hidden="true" />
          <p className="text-sm font-medium text-[var(--accent-default)]">
            {t('ovr.patient_data')}
          </p>
        </div>
        <PatientRevealToggle
          revealed={revealed}
          onToggle={() => setRevealed((prev) => !prev)}
          aria-label={t('a11y.masked_field_reveal')}
        />
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
        <MaskedField
          label={t('ovr.patient_name')}
          value={incident.patient_name ?? ''}
          revealed={revealed}
          onRevealedChange={setRevealed}
          hideToggle
        />
        <MaskedField
          label={t('ovr.patient_file_number')}
          value={incident.patient_file_number ?? ''}
          revealed={revealed}
          onRevealedChange={setRevealed}
          hideToggle
        />
      </div>
    </div>
  );
};

export default PatientDataPanel;