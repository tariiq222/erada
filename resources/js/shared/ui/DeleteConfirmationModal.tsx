import * as React from 'react';
import {IconBasket, IconX, IconTrash} from '@tabler/icons-react';
import { createPortal } from 'react-dom';
import { Button } from '@shared/ui';
import { useFocusTrap } from '@shared/lib/hooks/useFocusTrap';

export interface DeleteConfirmationModalProps<T> {
  isOpen: boolean;
  item: T | null;
  title: string;
  itemName: string;
  itemSubtitle?: string;
  warningMessage: string;
  confirmButtonText?: string;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

function DeleteConfirmationModal<T>({
  isOpen,
  item,
  title,
  itemName,
  itemSubtitle,
  warningMessage,
  confirmButtonText = 'حذف',
  isDeleting,
  onClose,
  onConfirm,
}: DeleteConfirmationModalProps<T>) {
  const open = isOpen && !!item;
  const panelRef = useFocusTrap<HTMLDivElement>(open);
  const titleId = React.useId();

  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !isDeleting) onClose();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, isDeleting, onClose]);

  if (!open) return null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        data-testid="modal-backdrop"
        className="absolute inset-0 bg-[var(--surface-overlay)] animate-in fade-in duration-150 motion-reduce:animate-none"
        onClick={() => !isDeleting && onClose()}
      />

      {/* Modal */}
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className="relative bg-[var(--surface-base)] rounded-2xl shadow-xl w-full max-w-md overflow-hidden outline-none animate-in zoom-in-95 duration-200 motion-reduce:animate-none"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border-default)]">
          <button
            type="button"
            onClick={() => !isDeleting && onClose()}
            className="p-1 rounded-full hover:bg-[var(--surface-muted)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
            disabled={isDeleting}
            aria-label="إغلاق"
          >
            <IconX className="h-5 w-5 text-[var(--text-tertiary)]" />
          </button>
          <h2 id={titleId} className="text-lg font-semibold text-[var(--text-primary)]">{title}</h2>
        </div>

        {/* Body */}
        <div className="p-5">
          <div className="flex items-start gap-4">
            <IconBasket className="h-9 w-9 text-[var(--status-danger)] shrink-0" aria-hidden />
            <div className="flex-1">
              <h3 className="font-semibold text-[var(--text-primary)] mb-2">
                هل أنت متأكد من حذف هذا العنصر؟
              </h3>
              <div className="bg-[var(--surface-muted)] rounded-xl p-3 mb-3">
                <p className="font-medium text-[var(--text-primary)]">{itemName}</p>
                {itemSubtitle && (
                  <p className="text-sm text-[var(--text-tertiary)]">{itemSubtitle}</p>
                )}
              </div>
              <p className="text-sm text-[var(--status-danger-text)]">
                <strong>تحذير:</strong> {warningMessage}
              </p>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 px-5 py-4 border-t border-[var(--border-default)] bg-[var(--surface-muted)]">
          <Button
            variant="outline"
            onClick={onClose}
            disabled={isDeleting}
          >
            إلغاء
          </Button>
          <Button
            variant="danger"
            onClick={onConfirm}
            loading={isDeleting}
            leftIcon={<IconTrash className="h-4 w-4" />}
          >
            {confirmButtonText}
          </Button>
        </div>
      </div>
    </div>,
    document.body
  );
}

export default DeleteConfirmationModal;
