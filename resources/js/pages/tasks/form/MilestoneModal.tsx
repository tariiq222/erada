import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconDeviceFloppy, IconFlag} from '@tabler/icons-react';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Modal, ModalHeader, ModalBody, ModalFooter } from '@shared/ui/Modal';
import { Select } from '@shared/ui/Select';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import type { MilestoneFormData, ValidationErrors } from './types';

interface MilestoneModalProps {
  isOpen: boolean;
  onClose: () => void;
  formData: MilestoneFormData;
  errors: ValidationErrors;
  isSaving: boolean;
  onChange: (field: keyof MilestoneFormData, value: string) => void;
  onSave: () => void;
}

const MilestoneModal = memo<MilestoneModalProps>(({
  isOpen,
  onClose,
  formData,
  errors,
  isSaving,
  onChange,
  onSave,
}) => {
  const { t } = useTranslation();
  return (
    <Modal open={isOpen} onClose={onClose} size="md">
      <ModalHeader onClose={onClose}>
        <div className="flex items-center gap-2">
          <IconFlag className="h-5 w-5 text-[var(--text-tertiary)]" />
          {t('tasks.add_new_milestone')}
        </div>
      </ModalHeader>
      <ModalBody>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
              {t('tasks.milestone_name')} <RequiredIndicator />
            </label>
            <Input
              value={formData.name}
              onChange={(e) => onChange('name', e.target.value)}
              placeholder={t('tasks.milestone_name_placeholder')}
              error={errors.name?.[0]}
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
              {t('tasks.milestone_description')}
            </label>
            <textarea
              value={formData.description}
              onChange={(e) => onChange('description', e.target.value)}
              placeholder={t('tasks.milestone_desc_placeholder')}
              rows={3}
              className="w-full px-4 py-2 border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] bg-[var(--surface-base)] text-[var(--text-primary)] resize-none"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
              {t('tasks.milestone_duration')} <RequiredIndicator />
            </label>
            <div className="flex gap-3">
              <div className="flex-1">
                <Input
                  type="number"
                  min="1"
                  value={formData.duration_value}
                  onChange={(e) => onChange('duration_value', e.target.value)}
                  placeholder={t('tasks.enter_number')}
                  error={errors.duration_value?.[0]}
                />
              </div>
              <div className="w-32">
                <Select
                  value={formData.duration_unit}
                  onChange={(e) => onChange('duration_unit', e.target.value)}
                  options={[
                    { value: 'day', label: t('tasks.unit_day') },
                    { value: 'week', label: t('tasks.unit_week') },
                    { value: 'month', label: t('tasks.unit_month') },
                  ]}
                />
              </div>
            </div>
            <p className="text-xs text-[var(--text-tertiary)] mt-1">
              {t('tasks.milestone_date_auto')}
            </p>
          </div>
        </div>
      </ModalBody>
      <ModalFooter>
        <Button type="button" variant="outline" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button
          type="button"
          onClick={onSave}
          loading={isSaving}
          leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
        >
          {t('tasks.create_milestone')}
        </Button>
      </ModalFooter>
    </Modal>
  );
});

MilestoneModal.displayName = 'MilestoneModal';

export default MilestoneModal;
