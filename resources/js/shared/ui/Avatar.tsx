import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface AvatarProps extends React.HTMLAttributes<HTMLDivElement> {
  src?: string;
  alt?: string;
  name?: string;
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  status?: 'online' | 'offline' | 'away' | 'busy';
}

function getInitials(name: string): string {
  const words = name.trim().split(' ');
  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }
  return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}

function getColorFromName(name: string): string {
  const colors = [
    'bg-[var(--accent-default)]',
    'bg-[var(--accent-hover)]',
    'bg-[var(--text-secondary)]',
    'bg-[var(--text-tertiary)]',
  ];
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash);
  }
  return colors[Math.abs(hash) % colors.length];
}

const Avatar = React.forwardRef<HTMLDivElement, AvatarProps>(
  ({ className, src, alt, name, size = 'md', status, ...props }, ref) => {
    const [imageError, setImageError] = React.useState(false);

    const sizes = {
      xs: 'h-6 w-6 text-xs',
      sm: 'h-8 w-8 text-sm',
      md: 'h-10 w-10 text-sm',
      lg: 'h-12 w-12 text-base',
      xl: 'h-16 w-16 text-lg',
    };

    const statusSizes = {
      xs: 'h-1.5 w-1.5',
      sm: 'h-2 w-2',
      md: 'h-2.5 w-2.5',
      lg: 'h-3 w-3',
      xl: 'h-4 w-4',
    };

    const statusColors = {
      online: 'bg-[var(--status-success)]',
      offline: 'bg-[var(--text-disabled)]',
      away: 'bg-[var(--status-warning)]',
      busy: 'bg-[var(--status-danger)]',
    };

    const showFallback = !src || imageError;

    return (
      <div
        ref={ref}
        data-testid="avatar"
        data-size={size}
        className={cn('relative inline-flex shrink-0', className)}
        {...props}
      >
        {showFallback ? (
          <div
            className={cn(
              'inline-flex items-center justify-center rounded-full text-[var(--text-inverse)] font-medium',
              sizes[size],
              name ? getColorFromName(name) : 'bg-[var(--text-disabled)]'
            )}
          >
            {name ? getInitials(name) : '?'}
          </div>
        ) : (
          <img
            src={src}
            alt={alt || name || 'Avatar'}
            onError={() => setImageError(true)}
            className={cn(
              'inline-block rounded-full object-cover',
              sizes[size]
            )}
          />
        )}
        {status && (
          <span
            role="img"
            aria-label={
              { online: 'متصل', offline: 'غير متصل', away: 'بعيد', busy: 'مشغول' }[status]
            }
            title={
              { online: 'متصل', offline: 'غير متصل', away: 'بعيد', busy: 'مشغول' }[status]
            }
            className={cn(
              'absolute bottom-0 end-0 block rounded-full ring-2 ring-[var(--surface-base)]',
              statusSizes[size],
              statusColors[status]
            )}
          />
        )}
      </div>
    );
  }
);

Avatar.displayName = 'Avatar';

export { Avatar };
