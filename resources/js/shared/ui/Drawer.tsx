import * as React from 'react';
import { cn } from '@shared/lib/utils';
import { useFocusTrap } from '@shared/lib/hooks/useFocusTrap';
import {IconX} from '@tabler/icons-react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';

export interface DrawerProps {
  open: boolean;
  onClose: () => void;
  children: React.ReactNode;
  position?: 'left' | 'right';
  size?: 'sm' | 'md' | 'lg' | 'xl';
  closeOnOverlay?: boolean;
  closeOnEscape?: boolean;
  ariaLabel?: string;
}

const Drawer: React.FC<DrawerProps> = ({
  open,
  onClose,
  children,
  position = 'right',
  size = 'md',
  closeOnOverlay = true,
  closeOnEscape = true,
  ariaLabel = 'لوحة جانبية',
}) => {
  const overlayRef = React.useRef<HTMLDivElement>(null);
  const panelRef = useFocusTrap<HTMLDivElement>(open);

  React.useEffect(() => {
    const handleEscape = (e: KeyboardEvent) => {
      if (closeOnEscape && e.key === 'Escape') {
        onClose();
      }
    };

    if (open) {
      document.addEventListener('keydown', handleEscape);
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = '';
    };
  }, [open, onClose, closeOnEscape]);

  const handleOverlayClick = (e: React.MouseEvent) => {
    if (closeOnOverlay && e.target === overlayRef.current) {
      onClose();
    }
  };

  const sizes = {
    sm: 'w-[85vw] sm:w-80',
    md: 'w-[90vw] sm:w-96',
    lg: 'w-[95vw] sm:w-[32rem]',
    xl: 'w-[95vw] sm:w-[40rem]',
  };

  const positions = {
    left: 'inset-y-0 start-0',
    right: 'inset-y-0 end-0',
  };

  const animations = {
    left: open
      ? 'translate-x-0'
      : '-translate-x-full rtl:translate-x-full',
    right: open
      ? 'translate-x-0'
      : 'translate-x-full rtl:-translate-x-full',
  };

  if (!open) return null;

  return createPortal(
    <div
      ref={overlayRef}
      onClick={handleOverlayClick}
      className={cn(
        'fixed inset-0 z-50 bg-[var(--surface-overlay)]',
        'animate-in fade-in duration-150 motion-reduce:animate-none'
      )}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={ariaLabel}
        tabIndex={-1}
        className={cn(
          'fixed bg-[var(--surface-base)] shadow-2xl h-full flex flex-col outline-none',
          'transition-transform duration-300 ease-out motion-reduce:transition-none',
          sizes[size],
          positions[position],
          animations[position]
        )}
      >
        {children}
      </div>
    </div>,
    document.body
  );
};

Drawer.displayName = 'Drawer';

export interface DrawerHeaderProps extends React.HTMLAttributes<HTMLDivElement> {
  onClose?: () => void;
  showCloseButton?: boolean;
}

const DrawerHeader = React.forwardRef<HTMLDivElement, DrawerHeaderProps>(
  ({ className, onClose, showCloseButton = true, children, ...props }, ref) => {
    const { t } = useTranslation();
    return (
    <div
      ref={ref}
      className={cn(
        'flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-[var(--border-default)] shrink-0',
        className
      )}
      {...props}
    >
      {showCloseButton && onClose && (
        <button
          onClick={onClose}
          className={cn(
            'rounded-lg p-1 sm:p-2 text-[var(--text-tertiary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-subtle)]',
            'transition-colors duration-200',
            'focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)]',
            'order-first rtl:order-first ltr:order-last'
          )}
          aria-label={t('common.close')}
        >
          <IconX className="h-4 w-4 sm:h-5 sm:w-5" />
        </button>
      )}
      <div className="text-base sm:text-lg font-semibold text-[var(--text-primary)] order-last rtl:order-last ltr:order-first">{children}</div>
    </div>
    );
  }
);

DrawerHeader.displayName = 'DrawerHeader';

const DrawerBody = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('flex-1 px-4 sm:px-6 py-3 sm:py-4 overflow-y-auto', className)}
    {...props}
  />
));

DrawerBody.displayName = 'DrawerBody';

const DrawerFooter = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn(
      'flex items-center justify-end gap-2 sm:gap-3 px-4 sm:px-6 py-3 sm:py-4 border-t border-[var(--border-default)] shrink-0',
      className
    )}
    {...props}
  />
));

DrawerFooter.displayName = 'DrawerFooter';

export { Drawer, DrawerHeader, DrawerBody, DrawerFooter };
