import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface KbdProps extends React.HTMLAttributes<HTMLElement> {
  children: React.ReactNode;
}

const Kbd = React.forwardRef<HTMLElement, KbdProps>(({ className, children, ...props }, ref) => (
  <kbd
    ref={ref}
    className={cn(
      'inline-flex items-center justify-center',
      'min-w-[18px] px-[6px] leading-[20px]',
      'font-mono text-[11px] text-center align-middle',
      'rounded-[6px] border border-[var(--border-2)]',
      'bg-[var(--surface-3)] text-[var(--text-2)]',
      className,
    )}
    {...props}
  >
    {children}
  </kbd>
));

Kbd.displayName = 'Kbd';

export { Kbd };
