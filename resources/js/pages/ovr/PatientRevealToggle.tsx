import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconEye, IconEyeOff } from '@tabler/icons-react';
import { IconButton } from '@shared/ui/IconButton';

export interface PatientRevealToggleProps {
  /** Whether the patient data block is currently in revealed state. */
  revealed: boolean;
  /** Toggle handler invoked when the user clicks the button. */
  onToggle: () => void;
  /** Optional accessible name override; defaults to a localized label. */
  'aria-label'?: string;
  /** Additional utility classes applied to the button. */
  className?: string;
}

/**
 * Standalone reveal/hide control for the patient data block. The actual
 * visibility state lives in the parent (typically the IncidentView page)
 * and is shared across the patient fields via the MaskedField primitive.
 */
const PatientRevealToggle: React.FC<PatientRevealToggleProps> = ({
  revealed,
  onToggle,
  'aria-label': ariaLabelOverride,
  className,
}) => {
  const { t } = useTranslation();

  const ariaLabel = ariaLabelOverride
    ?? (revealed ? t('common.hide') : t('common.reveal'));

  return (
    <IconButton
      variant="default"
      size="sm"
      type="button"
      onClick={onToggle}
      aria-label={ariaLabel}
      aria-pressed={revealed}
      title={ariaLabel}
      className={className}
    >
      {revealed ? (
        <IconEyeOff className="h-4 w-4" aria-hidden="true" />
      ) : (
        <IconEye className="h-4 w-4" aria-hidden="true" />
      )}
    </IconButton>
  );
};

export default PatientRevealToggle;