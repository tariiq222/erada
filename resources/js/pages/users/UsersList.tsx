import React, { useState, useEffect, useRef } from "react";
import { Link, useLocation } from "react-router-dom";
import { useTranslation } from "react-i18next";
import {IconUsers, IconUserPlus, IconFilter} from '@tabler/icons-react';
import { Button, PageHeader } from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import { departmentsApi } from '@entities/hr';
import { usersApi } from '@entities/user';
import { useCan } from "@shared/api/access";
import {
	type User,
	type PaginatedResponse,
	type Department,
	FiltersCard,
	UsersTable,
	DeleteUserModal,
} from "./components";

export const UsersList: React.FC = () => {
	const { t } = useTranslation();
	const canCreateUsers = useCan('users.create');
	const canEditUsers = useCan('users.edit');
	const canDeleteUsers = useCan('users.delete');
	const { showToast } = useToast();
	const base = useLocation().pathname.startsWith("/admin")
		? "/admin/users"
		: "/users";
	const [users, setUsers] = useState<User[]>([]);
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
		department_id: "",
		role: "",
		is_active: "",
	});
	const [deleteModal, setDeleteModal] = useState<{
		open: boolean;
		user: User | null;
	}>({
		open: false,
		user: null,
	});
	const [isDeleting, setIsDeleting] = useState(false);
	const [showFilters, setShowFilters] = useState(false);

	const fetchUsers = async (page = 1) => {
		setIsLoading(true);
		try {
			const params: Record<string, string> = {
				page: page.toString(),
				per_page: pagination.perPage.toString(),
			};

			if (filters.search) params.search = filters.search;
			if (filters.department_id) params.department_id = filters.department_id;
			if (filters.role) params.role = filters.role;
			if (filters.is_active) params.is_active = filters.is_active;

			const response = (await usersApi.getAll(params)) as PaginatedResponse;
			setUsers(response.data);
			setPagination({
				currentPage: response.current_page,
				lastPage: response.last_page,
				perPage: response.per_page,
				total: response.total,
			});
		} catch {
			showToast("error", t("users.load_error"));
		} finally {
			setIsLoading(false);
		}
	};

	const fetchDepartments = async () => {
		try {
			const response = (await departmentsApi.getList()) as Department[];
			setDepartments(response);
		} catch (error) {
			console.warn("Failed to load departments:", error);
		}
	};

	// تحميل البيانات عند mount وعند تغيير الفلاتر
	const isInitialMount = useRef(true);

	useEffect(() => {
		fetchUsers();
		fetchDepartments();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	useEffect(() => {
		// تجاهل التشغيل الأول (سيتم استدعاء fetchUsers من useEffect أعلاه)
		if (isInitialMount.current) {
			isInitialMount.current = false;
			return;
		}

		const timer = setTimeout(() => {
			fetchUsers(1);
		}, 300);
		return () => clearTimeout(timer);
	}, [filters.search, filters.department_id, filters.role, filters.is_active]);

	const handleDelete = async () => {
		if (!deleteModal.user) return;

		setIsDeleting(true);
		try {
			await usersApi.delete(deleteModal.user.id);
			showToast("success", t("users.delete_success"));
			setDeleteModal({ open: false, user: null });
			fetchUsers(pagination.currentPage);
		} catch {
			showToast("error", t("users.delete_error"));
		} finally {
			setIsDeleting(false);
		}
	};

	return (
		<div className="space-y-6">
			{/* Header */}
			<PageHeader
				title={t("users.title")}
				subtitle={t("users.subtitle")}
				icon={IconUsers}
				iconTone="admin"
				actions={
					<>
						<Button
							variant={showFilters ? "secondary" : "outline"}
							size="sm"
							leftIcon={<IconFilter className="h-4 w-4" />}
							onClick={() => setShowFilters(!showFilters)}
						>
							{t("common.filter")}
						</Button>
						{canCreateUsers && (
							<Link to={`${base}/create`}>
								<Button size="sm" leftIcon={<IconUserPlus className="h-4 w-4" />}>
									{t("users.add_user")}
								</Button>
							</Link>
						)}
					</>
				}
			/>

			{/* Filters */}
			{showFilters && (
				<FiltersCard
					filters={filters}
					departments={departments}
					onFiltersChange={setFilters}
					onClose={() => setShowFilters(false)}
				/>
			)}

			{/* IconUsers Table */}
			<UsersTable
				users={users}
				loading={isLoading}
				pagination={pagination}
				hasCreatePermission={canCreateUsers}
				hasEditPermission={canEditUsers}
				hasDeletePermission={canDeleteUsers}
				basePath={base}
				onPageChange={(page) => fetchUsers(page)}
				onDelete={(user) => setDeleteModal({ open: true, user })}
			/>

			{/* Delete Modal */}
			<DeleteUserModal
				isOpen={deleteModal.open}
				user={deleteModal.user}
				isDeleting={isDeleting}
				onClose={() => setDeleteModal({ open: false, user: null })}
				onConfirm={handleDelete}
			/>
		</div>
	);
};

export default UsersList;
