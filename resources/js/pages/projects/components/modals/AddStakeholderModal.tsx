import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUsers, IconCircleCheck, IconCircleX} from '@tabler/icons-react';
import { Button } from '@shared/ui';
import { stakeholderRoleOptions, stakeholderRoleLabels } from '../../constants';

interface AddStakeholderModalProps {
  isOpen: boolean;
  onClose: () => void;
  formData: {
    name: string;
    role: string;
    organization: string;
    email: string;
    phone: string;
  };
  onFormChange: (data: { name: string; role: string; organization: string; email: string; phone: string }) => void;
  onAdd: () => void;
  isLoading: boolean;
  onSelectFromUsers?: () => void;
}

const AddStakeholderModal: React.FC<AddStakeholderModalProps> = ({
  isOpen,
  onClose,
  formData,
  onFormChange,
  onAdd,
  isLoading,
  onSelectFromUsers,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();
  const [step, setStep] = useState<'details' | 'contact'>('details');

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  if (!isOpen) return null;

  const handleNext = () => {
    if (!formData.name || !formData.role) {
      return;
    }
    setStep('contact');
  };

  const handleBack = () => {
    setStep('details');
  };

  const handleClose = () => {
    setStep('details');
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={handleClose} />

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
            onClick={handleClose}
            aria-label={t('common.close')}
            className="p-1 rounded-full hover:bg-[var(--surface-muted)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            <IconCircleX className="h-5 w-5 text-[var(--text-secondary)]" />
          </button>
          <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{t('projects.add_stakeholder')}</h2>
        </div>

        {/* Body */}
        <div className="p-5">
          <div className="space-y-4">
            {/* Step 1: Basic Details */}
            {step === 'details' && (
              <>
                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.stakeholder_name')} *</label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => onFormChange({ ...formData, name: e.target.value })}
                    placeholder={t('projects.stakeholder_name_placeholder')}
                    className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.role')} *</label>
                  <div className="grid grid-cols-2 gap-2">
                    {stakeholderRoleOptions.map((role) => (
                      <button
                        key={role.value}
                        type="button"
                        onClick={() => onFormChange({ ...formData, role: role.value })}
                        className={`px-3 py-2 text-sm rounded-xl border transition-colors ${
                          formData.role === role.value
                            ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                            : 'border-[var(--border-default)] hover:border-[var(--border-hover)] text-[var(--text-secondary)]'
                        }`}
                      >
                        {role.label}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.organization')}</label>
                  <input
                    type="text"
                    value={formData.organization}
                    onChange={(e) => onFormChange({ ...formData, organization: e.target.value })}
                    placeholder={t('projects.organization_placeholder')}
                    className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                  />
                </div>

                {/* زر اختيار من المستخدمين */}
                {onSelectFromUsers && (
                  <button
                    type="button"
                    onClick={onSelectFromUsers}
                    className="w-full flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-[var(--border-default)] rounded-xl text-[var(--text-secondary)] hover:border-[var(--accent-default)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)]/50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
                  >
                    <IconUsers className="h-5 w-5" />
                    <span className="font-medium">{t('projects.select_from_existing_users')}</span>
                  </button>
                )}

                <div className="flex items-center justify-between pt-2">
                  <button
                    type="button"
                    onClick={handleClose}
                    className="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
                  >
                    {t('common.cancel')}
                  </button>
                  <Button
                    onClick={handleNext}
                    disabled={!formData.name || !formData.role}
                  >
                    {t('common.next')}
                  </Button>
                </div>
              </>
            )}

            {/* Step 2: Contact Info */}
            {step === 'contact' && (
              <>
                <div className="bg-[var(--surface-muted)] rounded-xl p-3">
                  <div className="flex items-center gap-2">
                    <IconCircleCheck className="h-5 w-5 text-[var(--status-success)]" />
                    <div>
                      <p className="text-sm font-medium text-[var(--text-primary)]">{formData.name}</p>
                      <p className="text-xs text-[var(--text-secondary)]">{stakeholderRoleLabels[formData.role] || formData.role} {formData.organization && `- ${formData.organization}`}</p>
                    </div>
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.email')}</label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={(e) => onFormChange({ ...formData, email: e.target.value })}
                    placeholder="email@example.com"
                    className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.phone')}</label>
                  <input
                    type="text"
                    value={formData.phone}
                    onChange={(e) => onFormChange({ ...formData, phone: e.target.value })}
                    placeholder="+966 5x xxx xxxx"
                    className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                  />
                </div>

                <div className="flex items-center justify-between pt-2">
                  <button
                    type="button"
                    onClick={handleBack}
                    className="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
                  >
                    {t('common.back')}
                  </button>
                  <Button onClick={onAdd} loading={isLoading}>
                    {t('projects.add_stakeholder_button')}
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default AddStakeholderModal;
