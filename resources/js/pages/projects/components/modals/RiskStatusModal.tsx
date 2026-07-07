import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleX, IconAlertTriangle, IconShield, IconCircleCheck} from '@tabler/icons-react';
import { Button } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';

interface RiskStatusModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentStatus: string;
  currentResponse?: string;
  onSave: (status: string, actionTaken: string) => void;
  isLoading: boolean;
}

const RiskStatusModal: React.FC<RiskStatusModalProps> = ({
  isOpen,
  onClose,
  currentStatus,
  currentResponse,
  onSave,
  isLoading,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();
  const [selectedStatus, setSelectedStatus] = useState(currentStatus);
  const [actionTaken, setActionTaken] = useState('');

  useEffect(() => {
    if (isOpen) {
      setSelectedStatus(currentStatus);
      setActionTaken('');
    }
  }, [isOpen, currentStatus]);

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const statusOptions = [
    {
      value: 'open',
      label: t('status.open'),
      description: t('projects.risk_status_open_desc'),
      icon: IconAlertTriangle,
      color: 'border-[var(--status-warning)] bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]',
      iconColor: 'text-[var(--status-warning)]',
    },
    {
      value: 'mitigated',
      label: t('status.mitigated'),
      description: t('projects.risk_status_mitigated_desc'),
      icon: IconShield,
      color: 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]',
      iconColor: 'text-[var(--accent-default)]',
    },
    {
      value: 'closed',
      label: t('status.closed'),
      description: t('projects.risk_status_closed_desc'),
      icon: IconCircleCheck,
      color: 'border-[var(--status-success)] bg-[var(--status-success-subtle)] text-[var(--status-success-text)]',
      iconColor: 'text-[var(--status-success)]',
    },
  ];

  const getActionLabel = () => {
    switch (selectedStatus) {
      case 'mitigated':
        return t('projects.mitigation_actions_label');
      case 'closed':
        return t('projects.closure_actions_label');
      default:
        return t('projects.additional_notes_optional');
    }
  };

  const getActionPlaceholder = () => {
    switch (selectedStatus) {
      case 'mitigated':
        return t('projects.mitigation_placeholder');
      case 'closed':
        return t('projects.closure_placeholder');
      default:
        return t('projects.notes_placeholder');
    }
  };

  const handleSave = () => {
    onSave(selectedStatus, actionTaken);
  };

  const isStatusChanged = selectedStatus !== currentStatus;
  const needsAction = (selectedStatus === 'mitigated' || selectedStatus === 'closed') && isStatusChanged;

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
            className="p-1 rounded-full hover:bg-[var(--surface-muted)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            <IconCircleX className="h-5 w-5 text-[var(--text-tertiary)]" />
          </button>
          <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{t('projects.change_risk_status')}</h2>
        </div>

        {/* Body */}
        <div className="p-5 space-y-5">
          {/* Status Options */}
          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-3">{t('projects.select_new_status')}</label>
            <div className="space-y-2">
              {statusOptions.map((option) => {
                const Icon = option.icon;
                const isSelected = selectedStatus === option.value;
                return (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => setSelectedStatus(option.value)}
                    className={`w-full p-3 rounded-xl border-2 transition-colors text-start focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] ${
                      isSelected
                        ? option.color
                        : 'border-[var(--border-default)] hover:border-[var(--border-strong)] bg-[var(--surface-base)]'
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <Icon className={`h-5 w-5 mt-0 ${isSelected ? option.iconColor : 'text-[var(--text-tertiary)]'}`} />
                      <div className="flex-1">
                        <div className="font-medium">{option.label}</div>
                        <div className={`text-xs mt-0 ${isSelected ? 'opacity-80' : 'text-[var(--text-tertiary)]'}`}>
                          {option.description}
                        </div>
                      </div>
                      {isSelected && (
                        <div className={`w-5 h-5 rounded-full flex items-center justify-center ${option.iconColor} bg-[var(--surface-base)]`}>
                          <IconCircleCheck className="h-4 w-4" />
                        </div>
                      )}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Action Taken - shows when status is changing to mitigated or closed */}
          {(isStatusChanged || actionTaken) && (
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                {getActionLabel()}
                {needsAction && <RequiredIndicator className="ms-1" />}
              </label>
              <textarea
                value={actionTaken}
                onChange={(e) => setActionTaken(e.target.value)}
                placeholder={getActionPlaceholder()}
                rows={3}
                className="w-full px-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)] transition-colors bg-[var(--surface-subtle)] resize-none"
              />
            </div>
          )}

          {/* Current Response Info */}
          {currentResponse && (
            <div className="bg-[var(--surface-subtle)] rounded-xl p-3 border border-[var(--border-default)]">
              <p className="text-xs text-[var(--text-tertiary)] mb-1">{t('projects.current_response_plan')}:</p>
              <p className="text-sm text-[var(--text-secondary)]">{currentResponse}</p>
            </div>
          )}

          {/* Actions */}
          <div className="flex items-center justify-between pt-2">
            <button
              type="button"
              onClick={onClose}
              className="text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
            >
              {t('common.cancel')}
            </button>
            <Button
              onClick={handleSave}
              loading={isLoading}
              disabled={!isStatusChanged || (needsAction && !actionTaken.trim())}
            >
              {t('common.save_changes')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default RiskStatusModal;
