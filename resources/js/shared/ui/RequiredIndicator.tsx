import { cn } from '@shared/lib/utils';

interface RequiredIndicatorProps {
  className?: string;
}

function RequiredIndicator({ className }: RequiredIndicatorProps) {
  return (
    <span data-testid="required-indicator" className={cn('text-[var(--status-danger)]', className)}>*</span>
  );
}

export { RequiredIndicator };
export type { RequiredIndicatorProps };
