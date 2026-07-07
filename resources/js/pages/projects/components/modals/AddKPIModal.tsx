import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleX} from '@tabler/icons-react';
import { Button } from '@shared/ui';

interface AddKPIModalProps {
  isOpen: boolean;
  onClose: () => void;
  formData: {
    indicator: string;
    target: string;
    current_value: string;
    unit: string;
  };
  onFormChange: (data: { indicator: string; target: string; current_value: string; unit: string }) => void;
  onAdd: () => void;
  isLoading: boolean;
}

const AddKPIModal: React.FC<AddKPIModalProps> = ({
  isOpen,
  onClose,
  formData,
  onFormChange,
  onAdd,
  isLoading,
}) => {
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
      {/* Backdrop */}
      <div data-testid="modal-backdrop" className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={onClose} />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border-default)]">
          <button
            type="button"
            onClick={onClose}
            aria-label={t('common.close')}
            className="p-1 rounded-full hover:bg-[var(--surface-subtle)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            <IconCircleX className="h-5 w-5 text-[var(--text-tertiary)]" />
          </button>
          <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{t('projects.add_kpi')}</h2>
        </div>

        {/* Body */}
        <div className="p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.kpi_indicator')} *</label>
            <input
              type="text"
              value={formData.indicator}
              onChange={(e) => onFormChange({ ...formData, indicator: e.target.value })}
              placeholder={t('projects.kpi_name_placeholder')}
              className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)] transition-colors bg-[var(--surface-subtle)]"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.kpi_target_value')} *</label>
              <input
                type="text"
                value={formData.target}
                onChange={(e) => onFormChange({ ...formData, target: e.target.value })}
                placeholder={t('projects.example_100')}
                className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)] transition-colors bg-[var(--surface-subtle)]"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.kpi_current_value')}</label>
              <input
                type="text"
                value={formData.current_value}
                onChange={(e) => onFormChange({ ...formData, current_value: e.target.value })}
                placeholder={t('projects.example_50')}
                className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)] transition-colors bg-[var(--surface-subtle)]"
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.kpi_unit')}</label>
            <input
              type="text"
              value={formData.unit}
              onChange={(e) => onFormChange({ ...formData, unit: e.target.value })}
              placeholder={t('projects.kpi_unit_placeholder')}
              className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)] transition-colors bg-[var(--surface-subtle)]"
            />
          </div>

          <div className="flex items-center justify-between pt-2">
            <button
              type="button"
              onClick={onClose}
              className="text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
            >
              {t('common.cancel')}
            </button>
            <Button
              onClick={onAdd}
              loading={isLoading}
              disabled={!formData.indicator || !formData.target}
            >
              {t('projects.add_kpi_button')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AddKPIModal;
