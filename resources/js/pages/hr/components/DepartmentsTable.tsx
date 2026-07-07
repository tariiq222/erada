import React from 'react';
import { useTranslation } from 'react-i18next';
import {
	IconNetwork,
	IconPlus,
	IconEdit,
	IconTrash,
	IconUsers,
} from '@tabler/icons-react';
import {
	Button,
	Badge,
	DataTable,
	RowAction,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Department } from './departmentTypes';
import {
	DEPARTMENT_LEVEL_LABELS,
	DEPARTMENT_LEVEL_COLORS,
} from './departmentTypes';

interface DepartmentsTableProps {
	departments: Department[];
	isLoading: boolean;
	pagination: {
		currentPage: number;
		lastPage: number;
		perPage: number;
		total: number;
	};
	onPageChange: (page: number) => void;
	canCreate?: boolean;
	canEdit?: boolean;
	canDelete?: boolean;
	onEdit: (department: Department) => void;
	onDelete: (department: Department) => void;
	onAddNew: () => void;
	onRowClick?: (department: Department) => void;
}

const DepartmentsTable: React.FC<DepartmentsTableProps> = ({
	departments,
	isLoading,
	pagination,
	onPageChange,
	canCreate = false,
	canEdit = false,
	canDelete = false,
	onEdit,
	onDelete,
	onAddNew,
	onRowClick,
}) => {
	const { t } = useTranslation();

	const columns: DataTableColumn<Department>[] = [
		{
			key: 'department',
			header: t('hr.department'),
			render: (dept) => (
				<div className="flex items-center gap-3">
					<div className="h-10 w-10 rounded-lg bg-[var(--accent-subtle)] flex items-center justify-center">
						<IconNetwork className="h-5 w-5 text-[var(--accent-default)]" />
					</div>
					<div>
						<button
							type="button"
							onClick={() => onRowClick?.(dept)}
							className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] hover:underline text-start transition-colors"
						>
							{dept.name}
						</button>
						{dept.code && (
							<p className="text-xs text-[var(--text-secondary)]">{dept.code}</p>
						)}
					</div>
				</div>
			),
		},
		{
			key: 'level',
			header: t('hr.level'),
			hideBelow: 'md',
			render: (dept) => (
				<Badge
					variant={(DEPARTMENT_LEVEL_COLORS[dept.level] as any) || 'default'}
					size="sm"
				>
					{dept.level_name ||
						DEPARTMENT_LEVEL_LABELS[dept.level] ||
						t('common.not_specified')}
				</Badge>
			),
		},
		{
			key: 'parent_department',
			header: t('hr.parent_department'),
			hideBelow: 'lg',
			render: (dept) =>
				dept.parent ? (
					<span className="text-[var(--text-primary)]">{dept.parent.name}</span>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
		{
			key: 'manager',
			header: t('hr.manager'),
			hideBelow: 'lg',
			render: (dept) =>
				dept.manager ? (
					<span className="text-[var(--text-primary)]">{dept.manager.name}</span>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
		{
			key: 'employees',
			header: t('hr.employees'),
			render: (dept) => (
				<div className="flex items-center gap-2">
					<IconUsers className="h-4 w-4 text-[var(--text-tertiary)]" />
					<span className="text-[var(--text-primary)]">{dept.employees_count}</span>
				</div>
			),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (dept) =>
				dept.is_active ? (
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

	return (
		<DataTable
			data={departments}
			loading={isLoading}
			rowKey={(dept) => dept.id}
			columns={columns}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={
				canCreate
					? {
							icon: IconNetwork,
							title: t('hr.no_departments'),
							description: t('hr.no_departments_desc'),
							action: (
								<Button onClick={onAddNew} leftIcon={<IconPlus className="h-4 w-4" />}>
									{t('hr.add_new_department')}
								</Button>
							),
						}
					: {
							icon: IconNetwork,
							title: t('hr.no_departments'),
							description: t('hr.no_departments_desc'),
						}
			}
			caption={t('hr.departments')}
			actions={(dept) => (
				<>
					{canEdit && (
						<RowAction icon={IconEdit} label={t('common.edit')} onClick={() => onEdit(dept)} />
					)}
					{canDelete && (
						<RowAction
							icon={IconTrash}
							label={t('common.delete')}
							onClick={() => onDelete(dept)}
							tone="danger"
						/>
					)}
				</>
			)}
		/>
	);
};

export default DepartmentsTable;