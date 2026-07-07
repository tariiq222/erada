import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { Employee } from './types';

interface DeleteEmployeeModalProps {
  isOpen: boolean;
  employee: Employee | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteEmployeeModal: React.FC<DeleteEmployeeModalProps> = ({ isOpen, employee, isDeleting, onClose, onConfirm }) => {
  const { t } = useTranslation();

  return (
    <DeleteConfirmationModal
      isOpen={isOpen}
      item={employee}
      title={t('hr.delete_employee')}
      itemName={employee?.name ?? ''}
      warningMessage={t('common.action_irreversible')}
      confirmButtonText={t('common.delete')}
      isDeleting={isDeleting}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
}

export default DeleteEmployeeModal;
