import * as React from 'react';
import { cn } from '@shared/lib/utils';
import { useFocusTrap } from '@shared/lib/hooks/useFocusTrap';
import {IconX} from '@tabler/icons-react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';

export interface ModalProps {
  open?: boolean;
  isOpen?: boolean;
  onClose: () => void;
  children: React.ReactNode;
  title?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
  closeOnOverlay?: boolean;
  closeOnEscape?: boolean;
}

const Modal: React.FC<ModalProps> = ({
  open,
  isOpen,
  onClose,
  children,
  title,
  size = 'md',
  closeOnOverlay = true,
  closeOnEscape = true,
}) => {
  const { t } = useTranslation();
  // Support both 'open' and 'isOpen' props
  const isModalOpen = open ?? isOpen ?? false;
  const overlayRef = React.useRef<HTMLDivElement>(null);
  const panelRef = useFocusTrap<HTMLDivElement>(isModalOpen);
  const titleId = React.useId();

  React.useEffect(() => {
    const handleEscape = (e: KeyboardEvent) => {
      if (closeOnEscape && e.key === 'Escape') {
        onClose();
      }
    };

    if (isModalOpen) {
      document.addEventListener('keydown', handleEscape);
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = '';
    };
  }, [isModalOpen, onClose, closeOnEscape]);

  const handleOverlayClick = (e: React.MouseEvent) => {
    if (closeOnOverlay && e.target === overlayRef.current) {
      onClose();
    }
  };

  const sizes = {
    sm: 'max-w-[calc(100%-1rem)] sm:max-w-sm',
    md: 'max-w-[calc(100%-1rem)] sm:max-w-lg',
    lg: 'max-w-[calc(100%-1rem)] sm:max-w-2xl',
    xl: 'max-w-[calc(100%-1rem)] sm:max-w-4xl',
    full: 'max-w-[calc(100%-1rem)] sm:max-w-[90vw] lg:max-w-6xl max-h-[calc(100%-2rem)]',
  };

  if (!isModalOpen) return null;

  return createPortal(
    <div
      ref={overlayRef}
      onClick={handleOverlayClick}
      className={cn(
        'fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4',
        'bg-[var(--surface-overlay)]',
        'animate-in fade-in duration-150 motion-reduce:animate-none'
      )}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        aria-label={title ? undefined : t('common.close')}
        tabIndex={-1}
        className={cn(
          'relative w-full bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-2xl outline-none',
          'animate-in zoom-in-95 duration-200 motion-reduce:animate-none',
          'max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-2rem)] overflow-hidden flex flex-col',
          sizes[size]
        )}
      >
        {title && (
          <div className="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-[var(--border-default)] shrink-0">
            <button
              onClick={onClose}
              className={cn(
                'rounded-lg p-1 sm:p-2 text-[var(--text-tertiary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-subtle)]',
                'transition-colors duration-200',
                'focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)]',
                'order-last'
              )}
              aria-label={t('common.close')}
            >
              <IconX className="h-4 w-4 sm:h-5 sm:w-5" />
            </button>
            <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)] order-first">{title}</h2>
          </div>
        )}
        <div className={cn('overflow-y-auto flex-1', title ? 'px-4 sm:px-6 py-3 sm:py-4' : '')}>{children}</div>
      </div>
    </div>,
    document.body
  );
};

Modal.displayName = 'Modal';

export interface ModalHeaderProps extends React.HTMLAttributes<HTMLDivElement> {
  onClose?: () => void;
  showCloseButton?: boolean;
}

const ModalHeader = React.forwardRef<HTMLDivElement, ModalHeaderProps>(
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
            'order-last'
          )}
          aria-label={t('common.close')}
        >
          <IconX className="h-4 w-4 sm:h-5 sm:w-5" />
        </button>
      )}
      <div className="text-base sm:text-lg font-semibold text-[var(--text-primary)] order-first">{children}</div>
    </div>
    );
  }
);

ModalHeader.displayName = 'ModalHeader';

const ModalBody = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('px-4 sm:px-6 py-3 sm:py-4 overflow-y-auto max-h-[50vh] sm:max-h-[60vh]', className)}
    {...props}
  />
));

ModalBody.displayName = 'ModalBody';

const ModalFooter = React.forwardRef<
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

ModalFooter.displayName = 'ModalFooter';

export { Modal, ModalHeader, ModalBody, ModalFooter };
