import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button, Modal, ModalHeader, ModalBody } from '@shared/ui';
import {IconLayoutKanban, IconPlus} from '@tabler/icons-react';
import type { UnlinkedProject } from './types';

interface LinkProjectModalProps {
  programId: number;
  isOpen: boolean;
  projects: UnlinkedProject[];
  linkingProjectId: number | null;
  onClose: () => void;
  onLinkProject: (projectId: number) => void;
}

const LinkProjectModal: React.FC<LinkProjectModalProps> = ({
  programId,
  isOpen,
  projects,
  linkingProjectId,
  onClose,
  onLinkProject,
}) => {
  const { t } = useTranslation();

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md">
      <ModalHeader onClose={onClose}>{t('strategy.programs.linkProjectToProgram')}</ModalHeader>
      <ModalBody>
        {projects.length === 0 ? (
          <div className="text-center py-8">
            <IconLayoutKanban className="w-12 h-12 mx-auto text-[var(--text-secondary)] mb-4" />
            <p className="text-[var(--text-secondary)]">{t('strategy.programs.noUnlinkedProjects')}</p>
            <Link to={`/projects/create?program_id=${programId}`}>
              <Button className="mt-4" leftIcon={<IconPlus className="w-4 h-4" />}>
                {t('strategy.programs.createNewProject')}
              </Button>
            </Link>
          </div>
        ) : (
          <div className="space-y-2">
            {projects.map((project) => (
              <div
                key={project.id}
                className="flex items-center justify-between p-3 rounded-lg border border-[var(--border-default)] hover:bg-[var(--surface-muted)]"
              >
                <div>
                  <p className="font-medium text-[var(--text-primary)]">{project.name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {project.code} {project.department && `• ${project.department.name}`}
                  </p>
                </div>
                <Button
                  size="sm"
                  onClick={() => onLinkProject(project.id)}
                  disabled={linkingProjectId === project.id}
                  loading={linkingProjectId === project.id}
                >
                  {t('strategy.programs.link')}
                </Button>
              </div>
            ))}
          </div>
        )}
      </ModalBody>
    </Modal>
  );
};

export default LinkProjectModal;
