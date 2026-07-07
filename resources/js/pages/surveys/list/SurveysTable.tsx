import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
	IconClipboardList,
	IconPlus,
	IconEye,
	IconSettings,
	IconSend,
	IconUsers,
	IconArchive,
	IconTrash,
} from '@tabler/icons-react';
import {
	Button,
	Badge,
	DataTable,
	RowAction,
	StatusBadge,
} from '@shared/ui';
import type { DataTableColumn } from '@shared/ui';
import type { Survey } from './types';
import { statusLabels, statusVariants, typeLabels, typeVariants } from './constants';

interface SurveysTableProps {
	surveys: Survey[];
	loading: boolean;
	pagination: { currentPage: number; lastPage: number; total: number };
	onPageChange: (page: number) => void;
	onPublish: (survey: Survey) => void;
	onClose: (survey: Survey) => void;
	onDelete: (survey: Survey) => void;
}

const formatDate = (date: string | null) => {
	if (!date) return '-';
	return new Date(date).toLocaleDateString('ar-EG-u-nu-latn');
};

const SurveysTable: React.FC<SurveysTableProps> = ({
	surveys,
	loading,
	pagination,
	onPageChange,
	onPublish,
	onClose,
	onDelete,
}) => {
	const { t } = useTranslation();

	const columns: DataTableColumn<Survey>[] = [
		{
			key: 'code',
			header: t('surveys.code'),
			render: (survey) => (
				<code className="text-xs bg-[var(--bg-tertiary)] px-2 py-1 rounded font-mono">
					{survey.code}
				</code>
			),
		},
		{
			key: 'title',
			header: t('common.title'),
			render: (survey) => (
				<div className="flex items-center gap-3">
					<div className="h-9 w-9 rounded-lg bg-[var(--accent-subtle)] flex items-center justify-center">
						<IconClipboardList className="h-4 w-4 text-[var(--accent-default)]" />
					</div>
					<div>
						<Link
							to={`/surveys/${survey.id}`}
							className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)] transition-colors"
						>
							{survey.title}
						</Link>
						{survey.description && (
							<p className="text-xs text-[var(--text-tertiary)] line-clamp-1 mt-0">
								{survey.description}
							</p>
						)}
					</div>
				</div>
			),
		},
		{
			key: 'type',
			header: t('surveys.type'),
			hideBelow: 'md',
			render: (survey) => (
				<Badge variant={typeVariants[survey.type]} size="sm">
					{t(typeLabels[survey.type])}
				</Badge>
			),
		},
		{
			key: 'status',
			header: t('common.status'),
			render: (survey) => (
				<StatusBadge
					type="custom"
					status={survey.status}
					label={t(statusLabels[survey.status])}
					color={statusVariants[survey.status]}
				/>
			),
		},
		{
			key: 'responses',
			header: t('surveys.responses'),
			hideBelow: 'md',
			render: (survey) => <span className="text-[var(--text-primary)]">{survey.responses_count}</span>,
		},
		{
			key: 'fields',
			header: t('surveys.fields'),
			hideBelow: 'lg',
			render: (survey) => <span className="text-[var(--text-primary)]">{survey.fields_count}</span>,
		},
		{
			key: 'created_at',
			header: t('common.created_at'),
			hideBelow: 'lg',
			render: (survey) => (
				<span className="text-[var(--text-secondary)]">{formatDate(survey.created_at)}</span>
			),
		},
	];

	return (
		<DataTable
			data={surveys}
			loading={loading}
			rowKey={(survey) => survey.id}
			columns={columns}
			rowHref={(survey) => `/surveys/${survey.id}`}
			pagination={{
				currentPage: pagination.currentPage,
				lastPage: pagination.lastPage,
				total: pagination.total,
				onPageChange,
			}}
			empty={{
				icon: IconClipboardList,
				title: t('surveys.no_surveys'),
				description: t('surveys.start_creating'),
				action: (
					<Link to="/surveys/create">
						<Button leftIcon={<IconPlus className="h-4 w-4" />}>
							{t('surveys.create_new')}
						</Button>
					</Link>
				),
			}}
			caption={t('surveys.title')}
			actions={(survey) => (
				<>
					<RowAction icon={IconEye} label={t('common.view')} to={`/surveys/${survey.id}`} />
					{survey.status === 'draft' && (
						<>
							<RowAction
								icon={IconSettings}
								label={t('surveys.build')}
								to={`/surveys/${survey.id}/builder`}
							/>
							<RowAction
								icon={IconSend}
								label={t('surveys.publish')}
								onClick={() => onPublish(survey)}
							/>
						</>
					)}
					{survey.status === 'published' && (
						<>
							<RowAction
								icon={IconUsers}
								label={t('surveys.responses')}
								to={`/surveys/${survey.id}/responses`}
							/>
							<RowAction
								icon={IconArchive}
								label={t('common.close')}
								onClick={() => onClose(survey)}
							/>
						</>
					)}
					{(survey.status === 'draft' ||
						(survey.status === 'closed' && survey.responses_count === 0)) && (
						<RowAction
							icon={IconTrash}
							label={t('common.delete')}
							onClick={() => onDelete(survey)}
							tone="danger"
						/>
					)}
				</>
			)}
		/>
	);
};

export default SurveysTable;