import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { User } from './types';

interface DeleteUserModalProps {
  isOpen: boolean;
  user: User | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteUserModal: React.FC<DeleteUserModalProps> = ({ isOpen, user, isDeleting, onClose, onConfirm }) => {
  const { t } = useTranslation();

  return (
    <DeleteConfirmationModal
      isOpen={isOpen}
      item={user}
      title={t('users.delete')}
      itemName={user?.name ?? ''}
      warningMessage={t('common.action_irreversible')}
      confirmButtonText={t('common.delete')}
      isDeleting={isDeleting}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
}

export default DeleteUserModal;
