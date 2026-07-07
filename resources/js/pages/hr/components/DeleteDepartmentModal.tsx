import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { Department } from './departmentTypes';

interface DeleteDepartmentModalProps {
  isOpen: boolean;
  department: Department | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteDepartmentModal: React.FC<DeleteDepartmentModalProps> = ({ isOpen, department, isDeleting, onClose, onConfirm }) => {
  const { t } = useTranslation();

  return (
    <DeleteConfirmationModal
      isOpen={isOpen}
      item={department}
      title={t('hr.delete_department')}
      itemName={department?.name ?? ''}
      warningMessage={t('common.action_irreversible')}
      confirmButtonText={t('common.delete')}
      isDeleting={isDeleting}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
}

export default DeleteDepartmentModal;
