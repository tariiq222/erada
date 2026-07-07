import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Card,
  Progress,
  Avatar,
  StatusBadge,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import {IconLayoutKanban, IconPlus, IconLink, IconEye, IconX} from '@tabler/icons-react';
import type { Project } from './types';

interface ProjectsTabProps {
  programId: number;
  projects: Project[];
  onOpenLinkModal: () => void;
  onUnlinkProject: (projectId: number) => void;
}

const ProjectsTab: React.FC<ProjectsTabProps> = ({
  programId,
  projects,
  onOpenLinkModal,
  onUnlinkProject,
}) => {
  const { t } = useTranslation();

  return (
    <div className="space-y-4">
      {/* Actions */}
      <div className="flex items-center gap-2">
        <Button onClick={onOpenLinkModal} variant="outline" size="sm" leftIcon={<IconLink className="h-4 w-4" />}>
          {t('strategy.programs.linkExistingProject')}
        </Button>
        <Link to={`/projects/create?program_id=${programId}`}>
          <Button size="sm" leftIcon={<IconPlus className="h-4 w-4" />}>
            {t('strategy.programs.createNewProject')}
          </Button>
        </Link>
      </div>

      {/* Projects List */}
      <Card className="border border-[var(--border-default)] overflow-hidden p-0">
        {projects.length === 0 ? (
          <div className="text-center py-12 px-6">
            <IconLayoutKanban className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
            <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('strategy.programs.noLinkedProjects')}</h3>
            <p className="text-[var(--text-tertiary)] mb-4">
              {t('strategy.programs.noLinkedProjectsDesc')}
            </p>
          </div>
        ) : (
          <Table hoverable>
            <TableHeader>
              <TableRow>
                <TableHead>{t('projects.project')}</TableHead>
                <TableHead>{t('common.status')}</TableHead>
                <TableHead>{t('common.priority')}</TableHead>
                <TableHead>{t('common.progress')}</TableHead>
                <TableHead>{t('projects.projectManager')}</TableHead>
                <TableHead className="w-24 text-center">{t('common.actions')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {projects.map((project) => (
                <TableRow key={project.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <div className="h-9 w-9 rounded-lg bg-[var(--support-indigo-subtle)] flex items-center justify-center">
                        <IconLayoutKanban className="h-4 w-4 text-[var(--support-indigo-text)]" />
                      </div>
                      <div>
                        <Link
                          to={`/projects/${project.id}`}
                          className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] transition-colors"
                        >
                          {project.name}
                        </Link>
                        <p className="text-xs text-[var(--text-tertiary)]">{project.code}</p>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell>
                    <StatusBadge type="project" status={project.status} size="sm" />
                  </TableCell>
                  <TableCell>
                    <StatusBadge type="priority" status={project.priority} size="sm" />
                  </TableCell>
                  <TableCell className="w-32">
                    <div className="space-y-1">
                      <Progress value={project.progress} size="sm" />
                      <span className="text-xs text-[var(--text-tertiary)]">
                        {Math.round(project.progress)}%
                      </span>
                    </div>
                  </TableCell>
                  <TableCell>
                    {project.manager ? (
                      <div className="flex items-center gap-2">
                        <Avatar name={project.manager.name} size="sm" />
                        <span className="text-[var(--text-secondary)] text-sm">{project.manager.name}</span>
                      </div>
                    ) : (
                      <span className="text-[var(--text-tertiary)] text-sm">-</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-center gap-1">
                      <Link
                        to={`/projects/${project.id}`}
                        className="p-2 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)] transition-colors"
                        title={t('common.view')}
                        aria-label={t('common.view')}
                      >
                        <IconEye className="h-4 w-4" />
                      </Link>
                      <IconButton
                        variant="danger"
                        onClick={() => onUnlinkProject(project.id)}
                        title={t('strategy.programs.unlink')}
                        aria-label={t('strategy.programs.unlink')}
                      >
                        <IconX className="h-4 w-4" />
                      </IconButton>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
    </div>
  );
};

export default ProjectsTab;
