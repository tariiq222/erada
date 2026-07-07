import * as React from 'react';
import { cn } from '@shared/lib/utils';

type IconButtonVariant = 'default' | 'danger' | 'warning' | 'success' | 'dangerStrong';
type IconButtonSize = 'none' | '2xs' | 'xs' | 'sm' | 'md';

interface IconButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: IconButtonVariant;
  size?: IconButtonSize;
}

const VARIANT: Record<IconButtonVariant, string> = {
  default:
    'text-[var(--text-tertiary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-muted)]',
  danger:
    'text-[var(--text-tertiary)] hover:bg-[var(--status-danger-subtle)] hover:text-[var(--status-danger)]',
  warning:
    'text-[var(--text-tertiary)] hover:bg-[var(--status-warning-subtle)] hover:text-[var(--status-warning)]',
  success:
    'text-[var(--text-tertiary)] hover:bg-[var(--status-success-subtle)] hover:text-[var(--status-success)]',
  dangerStrong:
    'text-[var(--status-danger)] hover:bg-[var(--status-danger-subtle)]',
};

const SIZE: Record<IconButtonSize, string> = {
  none: '',
  '2xs': 'h-6 w-6 p-0 inline-flex items-center justify-center',
  xs: 'h-7 w-7 p-0 inline-flex items-center justify-center',
  sm: 'p-1',
  md: 'p-2',
};

// ponytail: dev-only a11y guard. Icon-only buttons MUST have an accessible name
// (aria-label / title / aria-labelledby / visible text children) so screen reader
// users can identify them. Runtime check is more robust than a TS-level union
// because the label sometimes comes from a parent context we can't statically
// see, and a hard throw would break render. Silent in production.
function warnIfMissingAccessibleName(
  props: React.ButtonHTMLAttributes<HTMLButtonElement>,
): void {
  // Vite injects import.meta.env at build time. The warning stays on in
  // tests/dev and is stripped in production by Vite's dead-code elimination.
  if (import.meta.env.PROD) return;

  const hasAriaLabel =
    typeof props['aria-label'] === 'string' && props['aria-label'].trim() !== '';
  const hasAriaLabelledBy =
    typeof props['aria-labelledby'] === 'string' &&
    props['aria-labelledby'].trim() !== '';
  const hasTitle =
    typeof props.title === 'string' && props.title.trim() !== '';
  const hasTextChildren = React.Children.toArray(props.children).some((child) => {
    if (typeof child === 'string') return child.trim() !== '';
    if (typeof child === 'number') return true;
    return false;
  });

  if (!hasAriaLabel && !hasAriaLabelledBy && !hasTitle && !hasTextChildren) {
    // eslint-disable-next-line no-console
    console.error(
      'IconButton requires an accessible name (aria-label, title, or visible text children).',
    );
  }
}

const IconButton = React.forwardRef<HTMLButtonElement, IconButtonProps>(
  (
    { className, variant = 'default', size = 'md', type = 'button', ...props },
    ref,
  ) => {
    warnIfMissingAccessibleName(props);
    return (
      <button
        ref={ref}
        type={type}
        className={cn(
          'rounded-lg transition-colors',
          VARIANT[variant],
          SIZE[size],
          className,
        )}
        {...props}
      />
    );
  },
);
IconButton.displayName = 'IconButton';

export { IconButton };
export type { IconButtonProps, IconButtonVariant, IconButtonSize };
