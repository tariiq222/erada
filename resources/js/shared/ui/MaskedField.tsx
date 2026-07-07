import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { IconEye, IconEyeOff } from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
import { IconButton } from '@shared/ui/IconButton';

const DEFAULT_MASK = '••••••••';

export interface MaskedFieldProps {
  /** The actual value rendered when the user reveals the field. */
  value: string;
  /** Optional label rendered above the masked value (already localized by the caller). */
  label?: string;
  /**
   * The character used to mask the value when hidden. Defaults to 8 bullets
   * (`••••••••`). If a custom character is provided, exactly one character is
   * repeated to match the length of the underlying value.
   */
  maskChar?: string;
  /** Render the value in plain text on initial mount (skip the masking step). */
  defaultRevealed?: boolean;
  /** Controlled reveal state. When set, internal state is ignored. */
  revealed?: boolean;
  /** Called whenever the user clicks the toggle (controlled mode). */
  onRevealedChange?: (next: boolean) => void;
  /** Hide the per-field toggle button (use a parent toggle, e.g. PatientRevealToggle). */
  hideToggle?: boolean;
  /** Additional utility classes applied to the outer container. */
  className?: string;
  /** Override the automatically computed accessible name for the toggle. */
  'aria-label'?: string;
}

/**
 * MaskedField renders a sensitive string with a default-masked state and an
 * explicit user-driven reveal step. Use for PDPL-sensitive fields (patient
 * names, file numbers, national IDs, etc.) where the value should not be
 * visible without an intentional action by the viewer.
 */
export const MaskedField: React.FC<MaskedFieldProps> = ({
  value,
  label,
  maskChar = '•',
  defaultRevealed = false,
  revealed: revealedProp,
  onRevealedChange,
  hideToggle = false,
  className,
  'aria-label': ariaLabelOverride,
}) => {
  const { t } = useTranslation();
  const isControlled = revealedProp !== undefined;
  const [internalRevealed, setInternalRevealed] = React.useState<boolean>(defaultRevealed);
  const revealed = isControlled ? Boolean(revealedProp) : internalRevealed;

  const safeValue = value ?? '';
  const maskLength = Math.max(8, safeValue.length || 8);
  const maskText = maskChar.repeat(maskLength);

  const toggle = React.useCallback(() => {
    const next = !revealed;
    if (!isControlled) {
      setInternalRevealed(next);
    }
    onRevealedChange?.(next);
  }, [revealed, isControlled, onRevealedChange]);

  const baseAria = ariaLabelOverride ?? label ?? t('a11y.masked_field_reveal');
  const toggleAria = revealed
    ? t('a11y.masked_field_hide')
    : t('a11y.masked_field_reveal');

  return (
    <div className={cn('flex flex-col gap-1', className)}>
      {label && (
        <span className="text-xs text-[var(--text-tertiary)]">{label}</span>
      )}
      <div
        className={cn(
          'flex items-center gap-2 rounded-md border border-transparent',
          'px-2 py-1 -mx-2',
        )}
      >
        <span
          dir="ltr"
          className={cn(
            'font-mono text-sm font-medium text-[var(--text-primary)] break-all',
            !revealed && 'select-none',
          )}
          aria-label={revealed ? baseAria : t('a11y.masked_field_reveal')}
          aria-hidden={revealed ? undefined : 'false'}
        >
          {revealed ? safeValue : maskText}
        </span>
        {!hideToggle && (
          <IconButton
            variant="default"
            size="xs"
            onClick={toggle}
            aria-label={toggleAria}
            aria-pressed={revealed}
            title={toggleAria}
            type="button"
            className={cn(
              'shrink-0',
              'focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-1',
            )}
          >
            {revealed ? (
              <IconEyeOff className="h-4 w-4" aria-hidden="true" />
            ) : (
              <IconEye className="h-4 w-4" aria-hidden="true" />
            )}
          </IconButton>
        )}
      </div>
    </div>
  );
};

export default MaskedField;

// Default mask constant exported for testing/storybook parity.
export const MASKED_FIELD_DEFAULT_MASK = DEFAULT_MASK;