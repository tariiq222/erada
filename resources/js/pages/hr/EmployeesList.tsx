import React, { useCallback, useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router-dom";
import { IconSearch, IconPencil, IconUsers, IconUserPlus, IconId } from "@tabler/icons-react";
import {
	Card,
	CardContent,
	Button,
	Input,
	Select,
	Badge,
	PageHeader,
	Pagination,
	Skeleton,
	Table,
	TableHeader,
	TableBody,
	TableHead,
	TableRow,
	TableCell,
} from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import { useCan } from "@shared/api/access";
import { employeesApi } from "@entities/hr";
import type { Employee } from "@pages/hr/components/types";

const STATUS_VARIANT: Record<string, "success" | "warning" | "danger"> = {
	active: "success",
	suspended: "warning",
	terminated: "danger",
};

export const EmployeesList: React.FC = () => {
	const { t } = useTranslation();
	const { showToast } = useToast();
	const navigate = useNavigate();
	const canManage = useCan("hr.manage");
	const canCreateUser = useCan("users.create");

	const [employees, setEmployees] = useState<Employee[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [search, setSearch] = useState("");
	const [statusFilter, setStatusFilter] = useState("");
	const [page, setPage] = useState(1);
	const [lastPage, setLastPage] = useState(1);

	const statusOptions = [
		{ value: "", label: t("hr.all_statuses", "كل الحالات") },
		{ value: "active", label: t("hr.status_active", "نشط") },
		{ value: "suspended", label: t("hr.status_suspended", "موقوف") },
		{ value: "on_leave", label: t("hr.status_on_leave", "في إجازة") },
		{ value: "terminated", label: t("hr.status_terminated", "منتهي") },
	];

	const load = useCallback(async () => {
		setIsLoading(true);
		try {
			const params: Record<string, string> = { page: String(page) };
			if (search) params.search = search;
			if (statusFilter) params.status = statusFilter;
			const res: any = await employeesApi.getAll(params);
			setEmployees(res.data ?? []);
			setLastPage(res.last_page ?? 1);
		} catch (error: any) {
			showToast("error", error?.message || t("common.error_occurred"));
		} finally {
			setIsLoading(false);
		}
	}, [page, search, statusFilter, showToast, t]);

	useEffect(() => {
		void load();
	}, [load]);

	return (
		<div className="space-y-6">
			<PageHeader
				title={t("hr.employees", "الموظفون")}
				subtitle={t("hr.employees_subtitle", "إدارة الملفات الوظيفية")}
				icon={IconUsers}
				iconTone="admin"
				actions={
					canCreateUser ? (
						<Button onClick={() => navigate("/users/create")}>
							<IconUserPlus className="me-2 h-4 w-4" />
							{t("hr.add_employee", "إضافة موظف")}
						</Button>
					) : undefined
				}
			/>

			<Card>
				<CardContent className="space-y-4 p-4">
					<div className="flex flex-wrap items-end gap-3">
						<div className="min-w-[220px] flex-1">
							<Input
								label={t("common.search", "بحث")}
								value={search}
								onChange={(e) => {
									setPage(1);
									setSearch(e.target.value);
								}}
								placeholder={t("hr.search_employees", "اسم، بريد، رقم وظيفي")}
								leftIcon={<IconSearch className="h-4 w-4" />}
							/>
						</div>
						<div className="w-48">
							<Select
								label={t("hr.employment_status", "الحالة الوظيفية")}
								value={statusFilter}
								onChange={(e) => {
									setPage(1);
									setStatusFilter(e.target.value);
								}}
								options={statusOptions}
							/>
						</div>
					</div>

					{isLoading ? (
						<div className="p-6 space-y-4">
							{[...Array(5)].map((_, i) => (
								<div key={i} className="flex items-center gap-4">
									<Skeleton className="h-10 w-10 rounded-lg" />
									<div className="flex-1 space-y-2">
										<Skeleton className="h-4 w-48" />
										<Skeleton className="h-3 w-32" />
									</div>
									<Skeleton className="h-6 w-20 rounded-full" />
								</div>
							))}
						</div>
					) : employees.length === 0 ? (
						<div className="flex flex-col items-center gap-2 py-12 text-[var(--text-tertiary)]">
							<IconUsers className="h-8 w-8 opacity-50" />
							<span>{t("hr.no_employees", "لا يوجد موظفون")}</span>
						</div>
					) : (
						<div className="overflow-x-auto">
							<Table hoverable>
								<TableHeader>
									<TableRow>
										<TableHead>{t("hr.employee", "الموظف")}</TableHead>
										<TableHead>{t("nav.departments", "القسم")}</TableHead>
										<TableHead>{t("hr.job_title", "المسمى الوظيفي")}</TableHead>
										<TableHead>{t("hr.employee_no", "الرقم الوظيفي")}</TableHead>
										<TableHead>{t("hr.employment_status", "الحالة")}</TableHead>
										{canManage && <TableHead className="w-24 text-center" />}
									</TableRow>
								</TableHeader>
								<TableBody>
									{employees.map((emp) => {
										const profile = emp.employee_profile;
										const status = profile?.employment_status ?? "active";
										return (
											<TableRow key={emp.id}>
												<TableCell>
													<div className="font-medium text-[var(--text-primary)]">{emp.name}</div>
													<div className="text-xs text-[var(--text-tertiary)]">{emp.email}</div>
												</TableCell>
												<TableCell className="text-[var(--text-secondary)]">{emp.department?.name ?? "–"}</TableCell>
												<TableCell className="text-[var(--text-secondary)]">{emp.job_title ?? "–"}</TableCell>
												<TableCell className="text-[var(--text-secondary)]">{profile?.employee_no ?? "–"}</TableCell>
												<TableCell>
													<Badge variant={STATUS_VARIANT[status] ?? "default"} size="sm">
														{t(`hr.status_${status}`, status)}
													</Badge>
												</TableCell>
												{canManage && (
													<TableCell>
														<div className="flex items-center justify-end gap-1">
															{!emp.employee_profile && (
																<Button
																	variant="ghost"
																	size="sm"
																	onClick={() =>
																		navigate(
																			`/hr/employees/create?user_id=${emp.user_id}`
																		)
																	}
																	aria-label={t("hr.create_profile", "إنشاء ملف HR")}
																>
																	<IconId className="h-4 w-4" />
																</Button>
															)}
															<Button
																variant="ghost"
																size="sm"
																onClick={() => navigate(`/hr/employees/${emp.id}/edit`)}
																aria-label={t("common.edit", "تعديل")}
															>
																<IconPencil className="h-4 w-4" />
															</Button>
														</div>
													</TableCell>
												)}
											</TableRow>
										);
									})}
								</TableBody>
							</Table>
						</div>
					)}

					{lastPage > 1 && (
						<Pagination
							currentPage={page}
							totalPages={lastPage}
							onPageChange={setPage}
						/>
					)}
				</CardContent>
			</Card>
		</div>
	);
};

export default EmployeesList;
