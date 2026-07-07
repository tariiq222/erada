import * as React from 'react';
import { cn } from '@shared/lib/utils';
import {IconX, IconCircleCheck, IconAlertCircle, IconInfoCircle, IconAlertTriangle} from '@tabler/icons-react';
import { createPortal } from 'react-dom';

type ToastVariant = 'info' | 'success' | 'warning' | 'error';

interface Toast {
  id: string;
  variant: ToastVariant;
  title?: string;
  message: string;
  duration?: number;
}

interface ToastContextValue {
  toasts: Toast[];
  addToast: (toast: Omit<Toast, 'id'>) => void;
  removeToast: (id: string) => void;
}

const ToastContext = React.createContext<ToastContextValue | undefined>(undefined);

export function useToast() {
  const context = React.useContext(ToastContext);
  if (!context) {
    throw new Error('useToast must be used within a ToastProvider');
  }

  // Helper function for easier toast creation.
  // Depend on addToast only (stable) — depending on the whole context would
  // give showToast a new identity on every toast change and cause refetch loops.
  const { addToast } = context;
  const showToast = React.useCallback(
    (variant: ToastVariant, message: string, title?: string) => {
      addToast({ variant, message, title });
    },
    [addToast]
  );

  return { ...context, showToast };
}

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({
  children,
}) => {
  const [toasts, setToasts] = React.useState<Toast[]>([]);

  const addToast = React.useCallback((toast: Omit<Toast, 'id'>) => {
    const id = crypto.randomUUID();
    setToasts((prev) => [...prev, { ...toast, id }]);

    if (toast.duration !== 0) {
      setTimeout(() => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
      }, toast.duration || 5000);
    }
  }, []);

  const removeToast = React.useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const value = React.useMemo(
    () => ({ toasts, addToast, removeToast }),
    [toasts, addToast, removeToast]
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <ToastContainer />
    </ToastContext.Provider>
  );
};

const ToastContainer: React.FC = () => {
  const { toasts, removeToast } = useToast();

  if (toasts.length === 0) return null;

  return createPortal(
    <div
      className="fixed bottom-4 end-4 z-50 flex flex-col gap-2 max-w-sm w-full"
      aria-live="polite"
      aria-atomic="true"
    >
      {toasts.map((toast) => (
        <ToastItem key={toast.id} toast={toast} onClose={() => removeToast(toast.id)} />
      ))}
    </div>,
    document.body
  );
};

interface ToastItemProps {
  toast: Toast;
  onClose: () => void;
}

const ToastItem: React.FC<ToastItemProps> = ({ toast, onClose }) => {
  const closeButtonId = `toast-close-${toast.id}`;
  const variants = {
    info: {
      container: 'bg-[var(--surface-base)] border-[var(--accent-default)]/30',
      icon: <IconInfoCircle className="h-5 w-5 text-[var(--accent-default)]" />,
    },
    success: {
      container: 'bg-[var(--surface-base)] border-[var(--status-success)]/30',
      icon: <IconCircleCheck className="h-5 w-5 text-[var(--status-success)]" />,
    },
    warning: {
      container: 'bg-[var(--surface-base)] border-[var(--status-warning)]/30',
      icon: <IconAlertTriangle className="h-5 w-5 text-[var(--status-warning-text)]" />,
    },
    error: {
      container: 'bg-[var(--surface-base)] border-[var(--status-danger)]/30',
      icon: <IconAlertCircle className="h-5 w-5 text-[var(--status-danger-text)]" />,
    },
  };

  const variantConfig = variants[toast.variant] || variants.info;

  return (
    <div
      className={cn(
        'flex items-start gap-3 rounded-xl border p-4 shadow-lg',
        'animate-in slide-in-from-start-full duration-500 ease-out motion-reduce:animate-none',
        variantConfig.container
      )}
    >
      <div className="shrink-0">{variantConfig.icon}</div>
      <div className="flex-1 min-w-0">
        {toast.title && (
          <h6 className="font-semibold text-[var(--text-primary)] mb-0">{toast.title}</h6>
        )}
        <p className="text-sm text-[var(--text-secondary)]">{toast.message}</p>
      </div>
      <label htmlFor={closeButtonId} className="sr-only" aria-hidden="true">Close</label>
      <button
        id={closeButtonId}
        onClick={onClose}
        className={cn(
          'shrink-0 -me-2 -mt-2 flex h-11 w-11 items-center justify-center rounded-lg text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]',
          'transition-colors duration-200',
          'focus:outline-none focus:ring-2 focus:ring-[var(--border-strong)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)]'
        )}
        aria-label="إغلاق الإشعار / Close notification"
      >
        <IconX className="h-4 w-4" />
      </button>
    </div>
  );
};

export { ToastContainer, ToastItem };
