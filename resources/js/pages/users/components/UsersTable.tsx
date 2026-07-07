import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
	IconUsers,
	IconEye,
	IconEdit,
	IconTrash,
	IconMail,
	IconPhone,
	IconBuilding,
	IconUserPlus,
	IconUserCheck,
} from '@tabler/icons-react';
import {
	Button,
	Badge,
	Avatar,
	DataTable,
	RowAction,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { User } from './types';
import { roleLabels, roleColors } from './constants';

interface UsersTableProps {
	users: User[];
	loading: boolean;
	pagination: {
		currentPage: number;
		lastPage: number;
		perPage: number;
		total: number;
	};
	hasCreatePermission: boolean;
	hasEditPermission: boolean;
	hasDeletePermission: boolean;
	basePath: string;
	onPageChange: (page: number) => void;
	onDelete: (user: User) => void;
}

const UsersTable: React.FC<UsersTableProps> = ({
	users,
	loading,
	pagination,
	hasCreatePermission,
	hasEditPermission,
	hasDeletePermission,
	basePath,
	onPageChange,
	onDelete,
}) => {
	const { t } = useTranslation();

	const columns: DataTableColumn<User>[] = [
		{
			key: 'user',
			header: t('users.user'),
			render: (user) => (
				<div className="flex items-center gap-3">
					<Avatar name={user.name} size="sm" />
					<div>
						<Link
							to={`${basePath}/${user.id}`}
							className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] transition-colors"
						>
							{user.name}
						</Link>
						<div className="flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
							<IconMail className="h-3 w-3" />
							{user.email}
						</div>
					</div>
				</div>
			),
		},
		{
			key: 'contact_info',
			header: t('users.contact_info'),
			hideBelow: 'md',
			render: (user) => (
				<div className="text-sm space-y-1">
					{user.phone && (
						<div className="flex items-center gap-1 text-[var(--text-secondary)]">
							<IconPhone className="h-3 w-3" />
							{user.phone}
							{user.extension && (
								<span className="text-[var(--text-tertiary)] text-xs">#{user.extension}</span>
							)}
						</div>
					)}
					{user.job_title && (
						<div className="text-[var(--text-tertiary)]">{user.job_title}</div>
					)}
				</div>
			),
		},
		{
			key: 'department',
			header: t('users.department'),
			render: (user) =>
				user.department ? (
					<div className="flex items-center gap-2">
						<div className="h-7 w-7 rounded-lg bg-[var(--accent-subtle)] flex items-center justify-center">
							<IconBuilding className="h-3.5 w-3.5 text-[var(--accent-default)]" />
						</div>
						<span className="text-[var(--text-secondary)] text-sm">{user.department.name}</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)] text-sm">-</span>
				),
		},
		{
			key: 'roles',
			header: t('users.roles'),
			hideBelow: 'lg',
			render: (user) => (
				<div className="flex flex-wrap gap-1">
					{user.roles.map((role) => (
						<Badge key={role} variant={roleColors[role] || 'default'} size="sm">
							{t(roleLabels[role]) || role}
						</Badge>
					))}
				</div>
			),
		},
		{
			key: 'created_by',
			header: t('common.created_by'),
			hideBelow: 'lg',
			render: (user) =>
				user.creator ? (
					<div className="flex items-center gap-2">
						<div className="h-6 w-6 rounded-full bg-[var(--surface-muted)] flex items-center justify-center">
							<IconUserCheck className="h-3 w-3 text-[var(--text-tertiary)]" />
						</div>
						<span className="text-[var(--text-secondary)] text-sm">{user.creator.name}</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)] text-sm">-</span>
				),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (user) =>
				user.is_active ? (
					<Badge variant="success" size="sm">
						{t('common.active')}
					</Badge>
				) : (
					<Badge variant="danger" size="sm">
						{t('common.inactive')}
					</Badge>
				),
		},
	];

	const emptyAction = hasCreatePermission ? (
		<Link to={`${basePath}/create`}>
			<Button leftIcon={<IconUserPlus className="h-4 w-4" />}>
				{t('users.add_new_user')}
			</Button>
		</Link>
	) : undefined;

	return (
		<DataTable
			data={users}
			loading={loading}
			rowKey={(user) => user.id}
			columns={columns}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={{
				icon: IconUsers,
				title: t('users.no_users'),
				description: t('users.no_matching_users'),
				action: emptyAction,
			}}
			caption={t('users.title')}
			actions={(user) => (
				<>
					<RowAction icon={IconEye} label={t('common.view')} to={`${basePath}/${user.id}`} />
					{hasEditPermission && (
						<RowAction
							icon={IconEdit}
							label={t('common.edit')}
							to={`${basePath}/${user.id}/edit`}
						/>
					)}
					{hasDeletePermission && (
						<RowAction
							icon={IconTrash}
							label={t('common.delete')}
							onClick={() => onDelete(user)}
							tone="danger"
						/>
					)}
				</>
			)}
		/>
	);
};

export default UsersTable;