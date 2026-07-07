import React from 'react';
import { useTranslation } from 'react-i18next';
import {
	IconUserCircle,
	IconPlus,
	IconEdit,
	IconTrash,
	IconMail,
	IconPhone,
	IconBriefcase,
	IconBuilding,
} from '@tabler/icons-react';
import {
	Button,
	Avatar,
	Badge,
	DataTable,
	RowAction,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Employee } from './types';
import { statusLabels, statusColors } from './constants';

interface EmployeesTableProps {
	employees: Employee[];
	isLoading: boolean;
	pagination: { currentPage: number; lastPage: number; perPage: number; total: number };
	onPageChange: (page: number) => void;
	onEdit: (employee: Employee) => void;
	onDelete: (employee: Employee) => void;
	onAddNew: () => void;
}

const EmployeesTable: React.FC<EmployeesTableProps> = ({
	employees,
	isLoading,
	pagination,
	onPageChange,
	onEdit,
	onDelete,
	onAddNew,
}) => {
	const { t } = useTranslation();
	const resolveEmployeeNo = (emp: Employee): string => {
		const fromProfile = emp.employee_profile?.employee_no;
		if (fromProfile) return fromProfile;
		const legacy = (emp as unknown as { employee_number?: string }).employee_number;
		return legacy ?? '-';
	};
	const resolveStatus = (emp: Employee): string => {
		const fromProfile = emp.employee_profile?.employment_status;
		if (fromProfile) return fromProfile;
		const legacy = (emp as unknown as { status?: string }).status;
		return legacy ?? 'active';
	};

	const columns: DataTableColumn<Employee>[] = [
		{
			key: 'employee',
			header: t('hr.employee'),
			render: (emp) => (
				<div className="flex items-center gap-3">
					<Avatar name={emp.name} size="sm" />
					<div>
						<p className="font-medium text-[var(--text-primary)]">{emp.name}</p>
						<p className="text-xs text-[var(--text-secondary)]">{resolveEmployeeNo(emp)}</p>
					</div>
				</div>
			),
		},
		{
			key: 'contact_info',
			header: t('hr.contact_info'),
			hideBelow: 'md',
			render: (emp) => (
				<div className="text-sm space-y-1">
					{emp.email && (
						<div className="flex items-center gap-1 text-[var(--text-secondary)]">
							<IconMail className="h-3 w-3" />
							{emp.email}
						</div>
					)}
					{emp.phone && (
						<div className="flex items-center gap-1 text-[var(--text-secondary)]">
							<IconPhone className="h-3 w-3" />
							{emp.phone}
						</div>
					)}
				</div>
			),
		},
		{
			key: 'department',
			header: t('hr.department'),
			render: (emp) =>
				emp.department ? (
					<div className="flex items-center gap-2">
						<IconBuilding className="h-4 w-4 text-[var(--text-tertiary)]" />
						<span className="text-[var(--text-primary)]">{emp.department.name}</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
		{
			key: 'job_title',
			header: t('hr.job_title'),
			hideBelow: 'lg',
			render: (emp) =>
				emp.job_title ? (
					<div className="flex items-center gap-2">
						<IconBriefcase className="h-4 w-4 text-[var(--text-tertiary)]" />
						<span className="text-[var(--text-primary)]">{emp.job_title}</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (emp) => {
				const status = resolveStatus(emp);
				return (
					<Badge variant={statusColors[status] ?? 'default'} size="sm">
						{t(statusLabels[status] ?? 'hr.status_active')}
					</Badge>
				);
			},
		},
	];

	return (
		<DataTable
			data={employees}
			loading={isLoading}
			rowKey={(emp) => emp.id}
			columns={columns}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={{
				icon: IconUserCircle,
				title: t('hr.no_employees'),
				description: t('hr.no_employees_desc'),
				action: (
					<Button onClick={onAddNew} leftIcon={<IconPlus className="h-4 w-4" />}>
						{t('hr.add_new_employee')}
					</Button>
				),
			}}
			caption={t('hr.employees')}
			actions={(emp) => (
				<>
					<RowAction icon={IconEdit} label={t('common.edit')} onClick={() => onEdit(emp)} />
					<RowAction
						icon={IconTrash}
						label={t('common.delete')}
						onClick={() => onDelete(emp)}
						tone="danger"
					/>
				</>
			)}
		/>
	);
};

export default EmployeesTable;