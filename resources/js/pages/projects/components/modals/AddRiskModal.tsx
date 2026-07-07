import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleX} from '@tabler/icons-react';
import { Button } from '@shared/ui';
import { probabilityOptions, impactOptions, riskStatusOptions } from '../../constants';

interface RiskFormData {
  risk: string;
  probability: string;
  impact: string;
  response: string;
  status: string;
}

interface AddRiskModalProps {
  isOpen: boolean;
  onClose: () => void;
  formData: RiskFormData;
  onFormChange: (data: RiskFormData) => void;
  onAdd: () => void;
  isLoading: boolean;
  mode?: 'add' | 'edit';
}

const AddRiskModal: React.FC<AddRiskModalProps> = ({
  isOpen,
  onClose,
  formData,
  onFormChange,
  onAdd,
  isLoading,
  mode = 'add',
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

  const isEditMode = mode === 'edit';
  const title = isEditMode ? t('projects.edit_risk') : t('projects.add_risk');
  const buttonText = isEditMode ? t('common.save_changes') : t('projects.add_risk_button');

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={onClose} />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden max-h-[90vh] overflow-y-auto"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border-default)] sticky top-0 bg-[var(--surface-base)]">
          <button
            type="button"
            onClick={onClose}
            aria-label={t('common.close')}
            className="p-1 rounded-full hover:bg-[var(--surface-subtle)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            <IconCircleX className="h-5 w-5 text-[var(--text-tertiary)]" />
          </button>
          <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{title}</h2>
        </div>

        {/* Body */}
        <div className="p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.risk_description')} *</label>
            <textarea
              value={formData.risk}
              onChange={(e) => onFormChange({ ...formData, risk: e.target.value })}
              placeholder={t('projects.risk_description_placeholder')}
              rows={3}
              className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-subtle)] resize-none"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.probability')} *</label>
            <div className="grid grid-cols-3 gap-2">
              {probabilityOptions.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => onFormChange({ ...formData, probability: option.value })}
                  className={`px-3 py-2 text-sm rounded-xl border transition-colors ${
                    formData.probability === option.value
                      ? option.color
                      : 'border-[var(--border-default)] hover:border-[var(--border-strong)] text-[var(--text-secondary)]'
                  }`}
                >
                  {option.label}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.impact')} *</label>
            <div className="grid grid-cols-3 gap-2">
              {impactOptions.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => onFormChange({ ...formData, impact: option.value })}
                  className={`px-3 py-2 text-sm rounded-xl border transition-colors ${
                    formData.impact === option.value
                      ? option.color
                      : 'border-[var(--border-default)] hover:border-[var(--border-strong)] text-[var(--text-secondary)]'
                  }`}
                >
                  {option.label}
                </button>
              ))}
            </div>
          </div>

          {/* الحالة تظهر فقط عند الإضافة - عند التعديل تُغيّر من dropdown منفصل */}
          {!isEditMode && (
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('common.status')}</label>
              <div className="grid grid-cols-3 gap-2">
                {riskStatusOptions.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => onFormChange({ ...formData, status: option.value })}
                    className={`px-3 py-2 text-sm rounded-xl border transition-colors ${
                      formData.status === option.value
                        ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                        : 'border-[var(--border-default)] hover:border-[var(--border-strong)] text-[var(--text-secondary)]'
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.response_plan')}</label>
            <textarea
              value={formData.response}
              onChange={(e) => onFormChange({ ...formData, response: e.target.value })}
              placeholder={t('projects.response_plan_placeholder')}
              rows={2}
              className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-subtle)] resize-none"
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
              disabled={!formData.risk || !formData.probability || !formData.impact}
            >
              {buttonText}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AddRiskModal;
