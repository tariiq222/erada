import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { IconHelpCircle } from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
import { Tooltip } from './Tooltip';

export interface FieldHelpProps {
  /** Tooltip body: what the field is for and why it matters. */
  content: React.ReactNode;
  /** Accessible label for the trigger. Falls back to a translated default. */
  label?: string;
  position?: 'top' | 'bottom' | 'left' | 'right';
  className?: string;
}

/**
 * A small "?" affordance rendered next to a field label. On hover or keyboard
 * focus it reveals a tooltip explaining what the field needs and why. Reuses the
 * shared Tooltip so positioning and accessibility stay consistent.
 */
const FieldHelp: React.FC<FieldHelpProps> = ({
  content,
  label,
  position = 'top',
  className,
}) => {
  const { t } = useTranslation();

  return (
    <Tooltip
      position={position}
      content={
        <span className="block max-w-[16rem] text-start leading-relaxed">
          {content}
        </span>
      }
    >
      <button
        type="button"
        aria-label={label ?? t('common.field_help')}
        className={cn(
          'inline-flex shrink-0 items-center justify-center align-middle rounded-full text-[var(--text-tertiary)]',
          'transition-colors hover:text-[var(--accent-default)]',
          'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent-default)]',
          className
        )}
      >
        <IconHelpCircle className="h-4 w-4" aria-hidden="true" />
      </button>
    </Tooltip>
  );
};

FieldHelp.displayName = 'FieldHelp';

export { FieldHelp };
