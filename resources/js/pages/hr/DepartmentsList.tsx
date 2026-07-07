import React, { useState, useEffect } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router-dom";
import {IconNetwork, IconPlus, IconSearch, IconList, IconGitBranch} from '@tabler/icons-react';
import { Card, CardContent, Button, Input, PageHeader } from "@shared/ui";
import { cn } from "@shared/lib/utils";
import { useToast } from "@shared/ui/Toast";
import { useCan } from "@shared/api/access";
import { departmentsApi } from '@entities/hr';
import {
	DepartmentsTable,
	DeleteDepartmentModal,
	OrgChart,
} from "./components";
import type {
	DeptType as Department,
	DepartmentPaginatedResponse,
	TreeDepartment,
} from "./components";

export const DepartmentsList: React.FC = () => {
	const { t } = useTranslation();
	const { showToast } = useToast();
	const canCreate = useCan('departments.create');
	const canEdit = useCan('departments.edit');
	const canDelete = useCan('departments.delete');
	const navigate = useNavigate();
	const [viewMode, setViewMode] = useState<"table" | "chart">("table");
	const [departments, setDepartments] = useState<Department[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [pagination, setPagination] = useState({
		currentPage: 1,
		lastPage: 1,
		perPage: 15,
		total: 0,
	});
	const [filters, setFilters] = useState({
		search: "",
		active: "",
	});
	const [deleteModal, setDeleteModal] = useState<{
		open: boolean;
		department: Department | null;
	}>({
		open: false,
		department: null,
	});
	const [isDeleting, setIsDeleting] = useState(false);

	const fetchDepartments = async (page = 1) => {
		setIsLoading(true);
		try {
			const params: Record<string, string> = {
				page: page.toString(),
				per_page: pagination.perPage.toString(),
			};

			if (filters.search) params.search = filters.search;
			if (filters.active) params.active = filters.active;

			const response = (await departmentsApi.getAll(
				params,
			)) as DepartmentPaginatedResponse;
			setDepartments(response.data);
			setPagination({
				currentPage: response.current_page,
				lastPage: response.last_page,
				perPage: response.per_page,
				total: response.total,
			});
		} catch {
			showToast("error", t("hr.departments_load_error"));
		} finally {
			setIsLoading(false);
		}
	};

	// تحميل البيانات عند mount وعند تغيير الفلاتر
	const isInitialMount = React.useRef(true);

	useEffect(() => {
		fetchDepartments();
	}, []);

	useEffect(() => {
		// تجاهل التشغيل الأول (سيتم استدعاء fetchDepartments من useEffect أعلاه)
		if (isInitialMount.current) {
			isInitialMount.current = false;
			return;
		}

		const timer = setTimeout(() => {
			fetchDepartments(1);
		}, 300);
		return () => clearTimeout(timer);
	}, [filters.search, filters.active]);

	const handleDelete = async () => {
		if (!deleteModal.department) return;

		setIsDeleting(true);
		try {
			await departmentsApi.delete(deleteModal.department.id);
			showToast("success", t("hr.department_delete_success"));
			setDeleteModal({ open: false, department: null });
			fetchDepartments(pagination.currentPage);
		} catch (error: any) {
			showToast("error", error.message || t("hr.department_delete_error"));
		} finally {
			setIsDeleting(false);
		}
	};

	return (
		<div className="space-y-6">
			{/* Header */}
			<PageHeader
				title={t("hr.departments")}
				subtitle={t("hr.departments_subtitle")}
				icon={IconNetwork}
				iconTone="admin"
				actions={
					<div className="flex items-center gap-3">
					{/* View Mode Toggle */}
					<div className="flex items-center border border-[var(--border-default)] rounded-lg p-1 bg-[var(--surface-base)]">
						<button
							onClick={() => setViewMode("table")}
							className={cn(
								"flex items-center gap-1 px-3 py-1 rounded-md text-sm font-medium transition-colors",
								viewMode === "table"
									? "bg-[var(--accent-default)] text-[var(--text-inverse)]"
									: "text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]",
							)}
							title={t("hr.table_view")}
						>
							<IconList className="h-4 w-4" />
							<span className="hidden sm:inline">{t("hr.table")}</span>
						</button>
						<button
							onClick={() => setViewMode("chart")}
							className={cn(
								"flex items-center gap-1 px-3 py-1 rounded-md text-sm font-medium transition-colors",
								viewMode === "chart"
									? "bg-[var(--accent-default)] text-[var(--text-inverse)]"
									: "text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]",
							)}
							title={t("hr.chart_view")}
						>
							<IconGitBranch className="h-4 w-4" />
							<span className="hidden sm:inline">{t("hr.chart")}</span>
						</button>
					</div>
					{canCreate && (
						<Button
							onClick={() => navigate("/hr/departments/new")}
							leftIcon={<IconPlus className="h-4 w-4" />}
						>
							{t("hr.add_department")}
						</Button>
					)}
					</div>
				}
			/>

			{/* Content based on view mode */}
			{viewMode === "table" ? (
				<>
					{/* Filters */}
					<Card className="border border-[var(--border-default)]">
						<CardContent className="p-4">
							<div className="flex gap-4">
								<div className="flex-1">
									<Input
										placeholder={t("hr.search_departments")}
										value={filters.search}
										onChange={(e) =>
											setFilters({ ...filters, search: e.target.value })
										}
										leftIcon={<IconSearch className="h-4 w-4" />}
									/>
								</div>
							</div>
						</CardContent>
					</Card>

					{/* Table */}
					<DepartmentsTable
						departments={departments}
						isLoading={isLoading}
						pagination={pagination}
						onPageChange={fetchDepartments}
						canCreate={canCreate}
						canEdit={canEdit}
						canDelete={canDelete}
						onEdit={(dept) => navigate(`/hr/departments/${dept.id}/edit`)}
						onDelete={(dept) =>
							setDeleteModal({ open: true, department: dept })
						}
						onAddNew={() => navigate("/hr/departments/new")}
						onRowClick={(dept) => navigate(`/hr/departments/${dept.id}`)}
					/>
				</>
			) : (
				/* Organization Chart */
				<OrgChart
					canEdit={canEdit}
					onDepartmentClick={(dept: TreeDepartment) =>
						navigate(`/hr/departments/${dept.id}/edit`)
					}
				/>
			)}

			{/* Delete Modal */}
			<DeleteDepartmentModal
				isOpen={deleteModal.open}
				department={deleteModal.department}
				isDeleting={isDeleting}
				onClose={() => setDeleteModal({ open: false, department: null })}
				onConfirm={handleDelete}
			/>
		</div>
	);
};

export default DepartmentsList;
