import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { Program } from './types';

interface DeleteProgramModalProps {
  isOpen: boolean;
  program: Program | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteProgramModal: React.FC<DeleteProgramModalProps> = ({
  isOpen,
  program,
  isDeleting,
  onClose,
  onConfirm,
}) => {
  const { t } = useTranslation();

  return (
    <DeleteConfirmationModal
      isOpen={isOpen}
      item={program}
      title={t('strategy.programs.confirmDeleteProgram')}
      itemName={program?.name || ''}
      itemSubtitle={program?.code}
      warningMessage={t('strategy.programs.deleteProgramWarning')}
      confirmButtonText={t('strategy.programs.deleteProgram')}
      isDeleting={isDeleting}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
};

export default DeleteProgramModal;
