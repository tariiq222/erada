import * as React from 'react';
import { cn } from '@shared/lib/utils';

type StatusIconBoxStatus = 'ok' | 'info' | 'warn' | 'danger' | 'neutral';

interface StatusIconBoxProps {
  status: StatusIconBoxStatus;
  children: React.ReactNode;
  className?: string;
}

const STATUS_BG: Record<StatusIconBoxStatus, string> = {
  ok: 'bg-[var(--status-success-subtle)]',
  info: 'bg-[var(--accent-subtle)]',
  warn: 'bg-[var(--status-warning-subtle)]',
  danger: 'bg-[var(--status-danger-subtle)]',
  neutral: 'bg-[var(--border-strong)]',
};

function StatusIconBox({ status, children, className }: StatusIconBoxProps) {
  return (
    <div
      className={cn(
        'flex items-center justify-center w-8 h-8 rounded-lg',
        STATUS_BG[status],
        className,
      )}
    >
      {children}
    </div>
  );
}

export { StatusIconBox };
export type { StatusIconBoxProps, StatusIconBoxStatus };
