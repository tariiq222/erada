import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconChevronLeft, IconHome} from '@tabler/icons-react';

export interface BreadcrumbItem {
  label: string;
  href?: string;
  icon?: React.ReactNode;
}

export interface BreadcrumbProps extends React.HTMLAttributes<HTMLElement> {
  items: BreadcrumbItem[];
  separator?: React.ReactNode;
  showHome?: boolean;
  homeHref?: string;
}

const Breadcrumb = React.forwardRef<HTMLElement, BreadcrumbProps>(
  (
    {
      className,
      items,
      separator,
      showHome = true,
      homeHref = '/',
      ...props
    },
    ref
  ) => {
    const separatorElement = separator || (
      <IconChevronLeft className="h-4 w-4 text-[var(--text-tertiary)] mx-2 rtl:rotate-180" />
    );

    const allItems = showHome
      ? [{ label: 'الرئيسية', href: homeHref, icon: <IconHome className="h-4 w-4" /> }, ...items]
      : items;

    return (
      <nav
        ref={ref}
        aria-label="مسار التنقّل"
        className={cn('', className)}
        {...props}
      >
        <ol className="flex items-center flex-wrap">
          {allItems.map((item, index) => {
            const isLast = index === allItems.length - 1;

            return (
              <li key={index} className="flex items-center">
                {index > 0 && separatorElement}
                {item.href && !isLast ? (
                  <a
                    href={item.href}
                    className={cn(
                      'flex items-center gap-1 text-sm text-[var(--text-tertiary)] rounded',
                      'hover:text-[var(--accent-hover)] transition-colors duration-200',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]'
                    )}
                  >
                    {item.icon}
                    <span>{item.label}</span>
                  </a>
                ) : (
                  <span
                    className={cn(
                      'flex items-center gap-1 text-sm',
                      isLast ? 'text-[var(--text-primary)] font-medium' : 'text-[var(--text-tertiary)]'
                    )}
                    aria-current={isLast ? 'page' : undefined}
                  >
                    {item.icon}
                    <span>{item.label}</span>
                  </span>
                )}
              </li>
            );
          })}
        </ol>
      </nav>
    );
  }
);

Breadcrumb.displayName = 'Breadcrumb';

export { Breadcrumb };
