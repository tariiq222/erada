import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { Project } from './types';

interface DeleteProjectModalProps {
  isOpen: boolean;
  project: Project | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteProjectModal: React.FC<DeleteProjectModalProps> = ({
  isOpen,
  project,
  isDeleting,
  onClose,
  onConfirm,
}) => {
  const { t } = useTranslation();

  return (
  <DeleteConfirmationModal
    isOpen={isOpen}
    item={project}
    title={t('projects.delete_confirm_title')}
    itemName={project?.name || ''}
    itemSubtitle={project?.code}
    warningMessage={t('projects.delete_warning')}
    confirmButtonText={t('projects.delete')}
    isDeleting={isDeleting}
    onClose={onClose}
    onConfirm={onConfirm}
  />
  );
};

export default DeleteProjectModal;
