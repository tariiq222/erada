import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleX, IconEye, IconPencil, IconBuilding, IconMail, IconPhone, IconUser, IconDeviceFloppy} from '@tabler/icons-react';
import { Button } from '@shared/ui';
import { stakeholderRoleOptions, stakeholderRoleLabels, stakeholderInfluenceLabels } from '../../constants';

interface Stakeholder {
  id: number;
  name: string;
  role: string;
  organization?: string | null;
  email?: string | null;
  phone?: string | null;
  influence?: string;
}

interface ViewEditStakeholderModalProps {
  isOpen: boolean;
  onClose: () => void;
  stakeholder: Stakeholder | null;
  mode: 'view' | 'edit';
  onSave?: (data: Partial<Stakeholder>) => void;
  isLoading?: boolean;
}

const ViewEditStakeholderModal: React.FC<ViewEditStakeholderModalProps> = ({
  isOpen,
  onClose,
  stakeholder,
  mode: initialMode,
  onSave,
  isLoading = false,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();
  const [mode, setMode] = useState<'view' | 'edit'>(initialMode);
  const [formData, setFormData] = useState({
    name: '',
    role: '',
    organization: '',
    email: '',
    phone: '',
    influence: 'medium',
  });

  useEffect(() => {
    setMode(initialMode);
  }, [initialMode]);

  useEffect(() => {
    if (stakeholder) {
      setFormData({
        name: stakeholder.name || '',
        role: stakeholder.role || '',
        organization: stakeholder.organization || '',
        email: stakeholder.email || '',
        phone: stakeholder.phone || '',
        influence: stakeholder.influence || 'medium',
      });
    }
  }, [stakeholder]);

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  if (!isOpen || !stakeholder) return null;

  const handleSave = () => {
    if (onSave) {
      onSave({
        id: stakeholder.id,
        ...formData,
      });
    }
  };

  const handleClose = () => {
    setMode(initialMode);
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
          <div className="flex items-center gap-2">
            {mode === 'view' ? (
              <IconEye className="h-5 w-5 text-[var(--accent-default)]" />
            ) : (
              <IconPencil className="h-5 w-5 text-[var(--accent-default)]" />
            )}
            <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">
              {mode === 'view' ? t('projects.view_stakeholder') : t('projects.edit_stakeholder')}
            </h2>
          </div>
        </div>

        {/* Body */}
        <div className="p-5">
          {mode === 'view' ? (
            /* View Mode */
            <div className="space-y-4">
              {/* Avatar & Name */}
              <div className="flex items-center gap-4 pb-4 border-b border-[var(--border-default)]">
                <div className="h-14 w-14 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center">
                  <span className="text-[var(--accent-default)] font-bold text-xl">
                    {stakeholder.name.charAt(0)}
                  </span>
                </div>
                <div>
                  <h3 className="font-semibold text-lg text-[var(--text-primary)]">{stakeholder.name}</h3>
                  <p className="text-sm text-[var(--text-secondary)]">
                    {stakeholderRoleLabels[stakeholder.role] || stakeholder.role}
                  </p>
                </div>
              </div>

              {/* Details */}
              <div className="space-y-3">
                {stakeholder.organization && (
                  <div className="flex items-center gap-3">
                    <IconBuilding className="h-4 w-4 text-[var(--text-tertiary)]" />
                    <span className="text-[var(--text-primary)]">{stakeholder.organization}</span>
                  </div>
                )}
                {stakeholder.email && (
                  <div className="flex items-center gap-3">
                    <IconMail className="h-4 w-4 text-[var(--text-tertiary)]" />
                    <a href={`mailto:${stakeholder.email}`} className="text-[var(--accent-default)] hover:underline">
                      {stakeholder.email}
                    </a>
                  </div>
                )}
                {stakeholder.phone && (
                  <div className="flex items-center gap-3">
                    <IconPhone className="h-4 w-4 text-[var(--text-tertiary)]" />
                    <a href={`tel:${stakeholder.phone}`} className="text-[var(--accent-default)] hover:underline">
                      {stakeholder.phone}
                    </a>
                  </div>
                )}
                {stakeholder.influence && (
                  <div className="flex items-center gap-3">
                    <IconUser className="h-4 w-4 text-[var(--text-tertiary)]" />
                    <span className="text-[var(--text-primary)]">
                      {t('projects.influence_level')}: {stakeholderInfluenceLabels[stakeholder.influence] || stakeholder.influence}
                    </span>
                  </div>
                )}
              </div>

              {/* Actions */}
              <div className="flex items-center justify-between pt-4 border-t border-[var(--border-default)]">
                <button
                  type="button"
                  onClick={handleClose}
                  className="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
                >
                  {t('common.close')}
                </button>
                <Button
                  variant="outline"
                  leftIcon={<IconPencil className="h-4 w-4" />}
                  onClick={() => setMode('edit')}
                >
                  {t('common.edit')}
                </Button>
              </div>
            </div>
          ) : (
            /* Edit Mode */
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.stakeholder_name')} *</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
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
                      onClick={() => setFormData({ ...formData, role: role.value })}
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
                  onChange={(e) => setFormData({ ...formData, organization: e.target.value })}
                  placeholder={t('projects.organization_placeholder')}
                  className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.email')}</label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="email@example.com"
                  className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('projects.phone')}</label>
                <input
                  type="text"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  placeholder="+966 5x xxx xxxx"
                  className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-default)]/20 focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-muted)] text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)]"
                />
              </div>

              <div className="flex items-center justify-between pt-2">
                <button
                  type="button"
                  onClick={() => setMode('view')}
                  className="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
                >
                  {t('common.cancel')}
                </button>
                <Button
                  onClick={handleSave}
                  loading={isLoading}
                  disabled={!formData.name || !formData.role}
                  leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
                >
                  {t('common.save_changes')}
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default ViewEditStakeholderModal;
