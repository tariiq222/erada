import * as React from 'react';
import { cn } from '@shared/lib/utils';

/**
 * Visually hidden link that becomes visible on focus. Lets keyboard users skip
 * past the navigation chrome and jump to the page's primary content area.
 *
 * The target must exist on the same page (`id="main-content"` by default).
 */
export interface SkipToMainProps {
  /** Element to skip to. Must have an id matching `targetId`. */
  targetId?: string;
  /** Localized label rendered inside the anchor. */
  label?: string;
  className?: string;
}

const SkipToMain: React.FC<SkipToMainProps> = ({
  targetId = 'main-content',
  label,
  className,
}) => {
  return (
    <a
      href={`#${targetId}`}
      className={cn(
        // Visually hidden until focused, then visible.
        'sr-only focus:not-sr-only',
        'focus:fixed focus:top-3 focus:start-3 focus:z-50',
        'focus:rounded-md focus:border focus:border-[var(--accent-default)]',
        'focus:bg-[var(--surface-base)] focus:px-4 focus:py-2 focus:text-sm',
        'focus:font-medium focus:text-[var(--text-primary)]',
        'focus:shadow-[0_0_0_2px_var(--accent-subtle)]',
        className,
      )}
    >
      {label ?? 'Skip to main content'}
    </a>
  );
};

SkipToMain.displayName = 'SkipToMain';

export { SkipToMain };
