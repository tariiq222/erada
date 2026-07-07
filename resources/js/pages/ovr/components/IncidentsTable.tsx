import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import {
	IconAlertTriangle,
	IconPlus,
	IconEdit,
	IconEye,
	IconClock,
	IconUser,
} from '@tabler/icons-react';
import {
	Button,
	DataTable,
	RowAction,
	StatusBadge,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Incident } from './types';
import {
	severityLabels,
	statusLabels,
} from './constants';

// Map raw status/severity palette tokens onto StatusBadge's CustomBadgeColor set.
const severityBadgeColor: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
	low: 'success',
	medium: 'warning',
	high: 'danger',
	critical: 'danger',
};

const statusBadgeColor: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'secondary'> = {
	draft: 'secondary',
	new: 'info',
	under_review: 'info',
	pending_info: 'warning',
	in_progress: 'warning',
	resolved: 'success',
	closed: 'secondary',
	rejected: 'danger',
	archived: 'secondary',
};

interface IncidentsTableProps {
	incidents: Incident[];
	isLoading: boolean;
	pagination: {
		currentPage: number;
		lastPage: number;
		perPage: number;
		total: number;
	};
	onPageChange: (page: number) => void;
	canCreate?: boolean;
	canEditAll?: boolean;
	canEditOwn?: boolean;
	currentUserId?: number;
	onView: (incident: Incident) => void;
	onEdit: (incident: Incident) => void;
	onAddNew: () => void;
}

const IncidentsTable: React.FC<IncidentsTableProps> = ({
	incidents,
	isLoading,
	pagination,
	onPageChange,
	canCreate = false,
	canEditAll = false,
	canEditOwn = false,
	currentUserId,
	onView,
	onEdit,
	onAddNew,
}) => {
	const { t } = useTranslation();

	const columns: DataTableColumn<Incident>[] = [
		{
			key: 'report_number',
			header: t('ovr.report_number'),
			render: (incident) => (
				<div>
					<p className="font-mono font-medium text-[var(--text-primary)]">
						{incident.report_number}
					</p>
					<p className="text-sm text-[var(--text-secondary)] truncate max-w-[150px] sm:max-w-[200px] md:max-w-none">
						{incident.description || '-'}
					</p>
				</div>
			),
		},
		{
			key: 'incident_type',
			header: t('ovr.incident_type'),
			render: (incident) =>
				incident.incident_type ? (
					<span className="text-sm text-[var(--text-primary)]">
						{incident.incident_type.name}
					</span>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
		{
			key: 'date',
			header: t('ovr.date'),
			hideBelow: 'md',
			render: (incident) => (
				<div className="flex items-center gap-1 text-sm text-[var(--text-secondary)]">
					<IconClock className="h-3 w-3" />
					{incident.incident_datetime
						? formatDate(incident.incident_datetime)
						: formatDate(incident.created_at)}
				</div>
			),
		},
		{
			key: 'severity',
			header: t('ovr.severity'),
			render: (incident) => (
				<StatusBadge
					type="custom"
					status={incident.severity_level}
					label={t(severityLabels[incident.severity_level])}
					color={severityBadgeColor[incident.severity_level] ?? 'secondary'}
					size="sm"
				/>
			),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (incident) => (
				<StatusBadge
					type="custom"
					status={incident.status}
					label={t(statusLabels[incident.status])}
					color={statusBadgeColor[incident.status] ?? 'secondary'}
					size="sm"
				/>
			),
		},
		{
			key: 'reporter',
			header: t('ovr.reporter'),
			hideBelow: 'lg',
			render: (incident) =>
				incident.reporter ? (
					<div className="flex items-center gap-2">
						<IconUser className="h-4 w-4 text-[var(--text-tertiary)]" />
						<span className="text-[var(--text-primary)] text-sm">
							{incident.reporter.name}
						</span>
					</div>
				) : (
					<span className="text-[var(--text-tertiary)]">-</span>
				),
		},
	];

	const canEditRow = (incident: Incident) =>
		canEditAll || (canEditOwn && incident.reporter?.id === currentUserId);

	return (
		<DataTable
			data={incidents}
			loading={isLoading}
			rowKey={(incident) => incident.id}
			columns={columns}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={{
				icon: IconAlertTriangle,
				title: t('ovr.no_incidents'),
				description: t('ovr.start_reporting'),
				action: canCreate ? (
					<Button onClick={onAddNew} leftIcon={<IconPlus className="h-4 w-4" />}>
						{t('ovr.report_new_incident')}
					</Button>
				) : undefined,
			}}
			caption={t('ovr.incidents')}
			actions={(incident) => (
				<>
					<RowAction icon={IconEye} label={t('common.view_details')} onClick={() => onView(incident)} />
					{canEditRow(incident) && (
						<RowAction icon={IconEdit} label={t('common.edit')} onClick={() => onEdit(incident)} />
					)}
				</>
			)}
		/>
	);
};

export default IncidentsTable;