import React, { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { Button, PageHeader, IconAlertTriangle, IconPlus, IconDownload } from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import { SkipToMain } from "@shared/ui/SkipToMain";
import { useAuth } from "@shared/contexts/AuthContext";
import { useCan } from "@shared/api/access";
import { incidentsApi, incidentCategoriesApi } from '@entities/incident';
import {
	IncidentViewModal,
	FiltersCard,
	IncidentsTable,
} from "./components";
import type { Incident, Category, PaginatedResponse } from "./components";

export const IncidentsList: React.FC = () => {
	const { t } = useTranslation();
	const navigate = useNavigate();
	const { showToast } = useToast();
	const { user } = useAuth();
	const canViewStatistics = useCan('ovr.view_statistics');
	const canCreate = useCan('ovr.create');
	const canEdit = useCan('ovr.edit');
	const [incidents, setIncidents] = useState<Incident[]>([]);
	const [categories, setCategories] = useState<Category[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [pagination, setPagination] = useState({
		currentPage: 1,
		lastPage: 1,
		perPage: 15,
		total: 0,
	});
	const [filters, setFilters] = useState({
		search: "",
		category_id: "",
		severity: "",
		status: "",
	});
	const [viewModal, setViewModal] = useState<{
		open: boolean;
		incident: Incident | null;
	}>({
		open: false,
		incident: null,
	});

	const fetchIncidents = async (page = 1) => {
		setIsLoading(true);
		try {
			const params: Record<string, string> = {
				page: page.toString(),
				per_page: pagination.perPage.toString(),
			};

			if (filters.search) params.search = filters.search;
			if (filters.category_id) params.category_id = filters.category_id;
			if (filters.severity) params.severity = filters.severity;
			if (filters.status) params.status = filters.status;

			const response = (await incidentsApi.getAll(params)) as PaginatedResponse;
			setIncidents(response.data);
			setPagination({
				currentPage: response.current_page,
				lastPage: response.last_page,
				perPage: response.per_page,
				total: response.total,
			});
		} catch {
			showToast("error", t("ovr.load_error"));
		} finally {
			setIsLoading(false);
		}
	};

	const fetchCategories = async () => {
		try {
			const res = (await incidentCategoriesApi.getAll()) as
				| Category[]
				| { data: Category[] };
			const list = Array.isArray(res) ? res : (res?.data ?? []);
			setCategories(list);
		} catch (error) {
			console.warn("Failed to load categories:", error);
		}
	};

	useEffect(() => {
		fetchIncidents();
		fetchCategories();
	}, []);

	useEffect(() => {
		const timer = setTimeout(() => {
			fetchIncidents(1);
		}, 300);
		return () => clearTimeout(timer);
	}, [filters]);

	const exportParams: Record<string, string> = {};
	if (filters.search) exportParams.search = filters.search;
	if (filters.category_id) exportParams.category_id = filters.category_id;
	if (filters.severity) exportParams.severity = filters.severity;
	if (filters.status) exportParams.status = filters.status;

	return (
		<div id="main-content" className="space-y-6">
			<SkipToMain label={t("a11y.skip_to_main")} />
			{/* Header */}
			<PageHeader
				title={t("ovr.title")}
				subtitle={t("ovr.subtitle")}
				icon={IconAlertTriangle}
				iconTone="risk"
				actions={
					<>
						{canViewStatistics && (
							<a href={incidentsApi.exportUrl("csv", exportParams)} download>
								<Button
									variant="secondary"
									size="sm"
									leftIcon={<IconDownload className="h-4 w-4" />}
								>
									تصدير
								</Button>
							</a>
						)}
						{canCreate && (
							<Button
								onClick={() => navigate("/ovr/incidents/new")}
								leftIcon={<IconPlus className="h-4 w-4" />}
							>
								{t("ovr.report_incident")}
							</Button>
						)}
					</>
				}
			/>

			{/* Filters */}
			<FiltersCard
				filters={filters}
				categories={categories}
				onFiltersChange={setFilters}
			/>

			{/* Table */}
			<IncidentsTable
				incidents={incidents}
				isLoading={isLoading}
				pagination={pagination}
				onPageChange={fetchIncidents}
				onView={(incident) => setViewModal({ open: true, incident })}
				canCreate={canCreate}
				canEditAll={canEdit}
				canEditOwn={canEdit}
				currentUserId={user?.id}
				onEdit={(incident) => navigate(`/ovr/incidents/${incident.report_number}/edit`)}
				onAddNew={() => navigate("/ovr/incidents/new")}
			/>

			{/* View Modal */}
			<IncidentViewModal
				isOpen={viewModal.open}
				incident={viewModal.incident}
				onClose={() => setViewModal({ open: false, incident: null })}
			/>
		</div>
	);
};

export default IncidentsList;
