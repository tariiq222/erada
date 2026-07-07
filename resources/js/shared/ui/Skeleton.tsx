import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface SkeletonProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: 'text' | 'circular' | 'rectangular' | 'rounded';
  width?: string | number;
  height?: string | number;
  animation?: 'pulse' | 'wave' | 'none';
}

const Skeleton = React.forwardRef<HTMLDivElement, SkeletonProps>(
  (
    {
      className,
      variant = 'text',
      width,
      height,
      animation = 'pulse',
      ...props
    },
    ref
  ) => {
    const variants = {
      text: 'rounded h-4',
      circular: 'rounded-full',
      rectangular: '',
      rounded: 'rounded-lg',
    };

    const animations = {
      pulse: 'animate-pulse',
      wave: 'animate-pulse', // Same as pulse - kept for API compatibility
      none: '',
    };

    const style: React.CSSProperties = {};
    if (width) style.width = typeof width === 'number' ? `${width}px` : width;
    if (height) style.height = typeof height === 'number' ? `${height}px` : height;

    return (
      <div
        ref={ref}
        data-testid="skeleton"
        className={cn(
          'bg-[var(--surface-muted)]',
          variants[variant],
          animations[animation],
          className
        )}
        style={style}
        {...props}
      />
    );
  }
);

Skeleton.displayName = 'Skeleton';

const SkeletonText: React.FC<{
  lines?: number;
  className?: string;
}> = ({ lines = 3, className }) => (
  <div className={cn('space-y-2', className)}>
    {Array.from({ length: lines }).map((_, i) => (
      <Skeleton
        key={i}
        variant="text"
        width={i === lines - 1 ? '75%' : '100%'}
      />
    ))}
  </div>
);

SkeletonText.displayName = 'SkeletonText';

const SkeletonCard: React.FC<{
  className?: string;
  showImage?: boolean;
}> = ({ className, showImage = true }) => (
  <div className={cn('rounded-xl border border-[var(--border-default)] p-4', className)}>
    {showImage && (
      <Skeleton variant="rounded" height={160} className="mb-4" />
    )}
    <Skeleton variant="text" width="60%" className="mb-2 h-5" />
    <SkeletonText lines={2} />
    <div className="flex gap-2 mt-4">
      <Skeleton variant="rounded" width={80} height={32} />
      <Skeleton variant="rounded" width={80} height={32} />
    </div>
  </div>
);

SkeletonCard.displayName = 'SkeletonCard';

const SkeletonTable: React.FC<{
  rows?: number;
  columns?: number;
  className?: string;
}> = ({ rows = 5, columns = 4, className }) => (
  <div className={cn('rounded-xl border border-[var(--border-default)] overflow-hidden', className)}>
    <div className="bg-[var(--surface-subtle)] border-b border-[var(--border-default)] p-4">
      <div className="flex gap-4">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton key={i} variant="text" className="flex-1 h-4" />
        ))}
      </div>
    </div>
    <div className="divide-y divide-[var(--border-default)]">
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={rowIndex} className="p-4">
          <div className="flex gap-4">
            {Array.from({ length: columns }).map((_, colIndex) => (
              <Skeleton key={colIndex} variant="text" className="flex-1 h-4" />
            ))}
          </div>
        </div>
      ))}
    </div>
  </div>
);

SkeletonTable.displayName = 'SkeletonTable';

export { Skeleton, SkeletonText, SkeletonCard, SkeletonTable };
