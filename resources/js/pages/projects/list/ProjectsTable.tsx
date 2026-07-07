import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
	IconLayoutKanban,
	IconPlus,
	IconEye,
	IconEdit,
	IconTrash,
	IconLink,
} from '@tabler/icons-react';
import {
	Button,
	Progress,
	DataTable,
	RowAction,
	Avatar,
	StatusBadge,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Project } from './types';
import { projectTypeTokens } from './constants';

interface ProjectsTableProps {
	projects: Project[];
	loading: boolean;
	pagination: { currentPage: number; lastPage: number; total: number };
	// Module-level delete capability (resolves `projects.delete` via the
	// canonical engine path). Required because the row-level `abilities.delete`
	// payload is the source of truth for "can I delete THIS project" — the
	// module capability gates the *availability* of the action column at all.
	canDeleteProject: boolean;
	onPageChange: (page: number) => void;
	onDelete: (project: Project) => void;
	onCreate?: () => void;
}

const ProjectsTable: React.FC<ProjectsTableProps> = ({
	projects,
	loading,
	pagination,
	canDeleteProject,
	onPageChange,
	onDelete,
	onCreate,
}) => {
	const { t } = useTranslation();

	const columns: DataTableColumn<Project>[] = [
		{
			key: 'name',
			header: t('projects.project_name'),
			render: (project) => {
				const token = projectTypeTokens[project.type] ?? projectTypeTokens.development;
				const TypeIcon = token.icon;
				return (
					<div className="flex items-center gap-3">
						<div
							className="h-9 w-9 rounded-lg flex items-center justify-center"
							style={{ backgroundColor: token.bg }}
						>
							<TypeIcon className="h-4 w-4" style={{ color: token.fg }} />
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
				);
			},
		},
		{
			key: 'department',
			header: t('projects.project_department'),
			hideBelow: 'md',
			render: (project) => (
				<span className="text-[var(--text-secondary)] text-sm">
					{project.department?.name || '-'}
				</span>
			),
		},
		{
			key: 'initiative',
			header: t('projects.initiative'),
			hideBelow: 'lg',
			render: (project) =>
				project.program ? (
					<Link
						to={`/strategy/programs/${project.program.id}`}
						className="inline-flex items-center gap-1 text-[var(--accent-default)] hover:underline text-sm"
					>
						<IconLink className="h-3 w-3" />
						{project.program.code}
					</Link>
				) : (
					<span className="text-xs text-[var(--text-tertiary)]">
						{t('projects.independent')}
					</span>
				),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (project) => <StatusBadge type="project" status={project.status} size="sm" />,
		},
		{
			key: 'priority',
			header: t('common.priority'),
			render: (project) => <StatusBadge type="priority" status={project.priority} size="sm" />,
		},
		{
			key: 'progress',
			header: t('common.progress'),
			hideBelow: 'md',
			cellClassName: 'w-32',
			render: (project) => (
				<div className="space-y-1">
					<Progress value={project.progress} size="sm" />
					<span className="text-xs text-[var(--text-tertiary)]">
						{Math.round(project.progress)}%
					</span>
				</div>
			),
		},
		{
			key: 'manager',
			header: t('projects.project_manager'),
			hideBelow: 'lg',
			render: (project) =>
				project.manager ? (
					<div className="flex items-center gap-2">
						<Avatar name={project.manager.name} size="xs" />
						<span className="text-[var(--text-secondary)] text-sm">{project.manager.name}</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)] text-sm">-</span>
				),
		},
	];

	const emptyAction = onCreate ? (
		<Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={onCreate}>
			{t('projects.create_new')}
		</Button>
	) : (
		<Link to="/projects/create">
			<Button leftIcon={<IconPlus className="h-4 w-4" />}>{t('projects.create_new')}</Button>
		</Link>
	);

	return (
		<DataTable
			data={projects}
			loading={loading}
			rowKey={(project) => project.id}
			columns={columns}
			rowHref={(project) => `/projects/${project.id}`}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={{
				icon: IconLayoutKanban,
				title: t('projects.no_projects'),
				description: t('projects.start_first'),
				action: emptyAction,
			}}
			caption={t('projects.title')}
			actions={(project) => (
				<>
					<RowAction icon={IconEye} label={t('common.view')} to={`/projects/${project.id}`} />
					<RowAction
						icon={IconEdit}
						label={t('common.edit')}
						to={`/projects/${project.id}/edit`}
					/>
					{canDeleteProject && project.abilities?.delete && (
						<RowAction
							icon={IconTrash}
							label={t('common.delete')}
							onClick={() => onDelete(project)}
							tone="danger"
						/>
					)}
				</>
			)}
		/>
	);
};

export default ProjectsTable;