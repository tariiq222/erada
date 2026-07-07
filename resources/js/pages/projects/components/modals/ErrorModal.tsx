import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleX} from '@tabler/icons-react';

interface ErrorModalProps {
  isOpen: boolean;
  onClose: () => void;
  message: string;
}

const ErrorModal: React.FC<ErrorModalProps> = ({ isOpen, onClose, message }) => {
  const { t } = useTranslation();
  const titleId = React.useId();

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div data-testid="modal-backdrop" className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-xl w-full max-w-sm mx-4 overflow-hidden"
      >
        <div className="p-5 text-center">
          <div className="mx-auto w-12 h-12 rounded-full bg-[var(--status-danger-subtle)] flex items-center justify-center mb-4">
            <IconCircleX className="h-6 w-6 text-[var(--status-danger-text)]" />
          </div>
          <h3 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)] mb-2">{t('projects.not_allowed')}</h3>
          <p className="text-[var(--text-secondary)] mb-4">{message}</p>
          <button
            onClick={onClose}
            className="px-4 py-2 bg-[var(--surface-subtle)] text-[var(--text-secondary)] rounded-lg hover:bg-[var(--surface-muted)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            {t('projects.ok')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ErrorModal;
