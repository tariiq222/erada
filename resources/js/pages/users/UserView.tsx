import React, { useState, useEffect } from "react";
import { useParams, Link, useNavigate, useLocation } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { formatDate } from "@shared/lib/utils";
import {IconUser, IconMail, IconPhone, IconBuilding, IconShield, IconCalendar, IconEdit, IconTrash, IconCircleCheck, IconCircleX, IconBriefcase, IconLayoutKanban, IconClipboardList, IconUserCheck, IconUserCog} from '@tabler/icons-react';
import { Card, CardContent } from "@shared/ui/Card";
import { Button } from "@shared/ui/Button";
import { Badge } from "@shared/ui/Badge";
import { Breadcrumb } from "@shared/ui/Breadcrumb";
import { PageHeader } from "@shared/ui/PageHeader";
import { StatusBadge } from "@shared/ui/StatusBadge";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@shared/ui/Tabs";
import { Modal } from "@shared/ui/Modal";
import { Skeleton } from "@shared/ui/Skeleton";
import { useToast } from "@shared/ui/Toast";
import { usersApi } from '@entities/user';
import { useCan } from "@shared/api/access";
import { UserSecurityCard } from './components';

interface UserDetail {
	id: number;
	name: string;
	email: string;
	phone: string | null;
	extension: string | null;
	job_title: string | null;
	is_active: boolean;
	department: {
		id: number;
		name: string;
	} | null;
	creator: {
		id: number;
		name: string;
	} | null;
	updater: {
		id: number;
		name: string;
	} | null;
	roles: string[];
	permissions: string[];
	capabilities?: string[];
	created_at: string;
	updated_at: string;
	managed_projects: {
		id: number;
		name: string;
		status: string;
	}[];
	tasks: {
		id: number;
		title: string;
		status: string;
		project: { id: number; name: string };
	}[];
}

const roleLabels: Record<string, string> = {
	super_admin: "role.super_admin",
	admin: "role.admin",
	project_manager: "role.project_manager",
	team_member: "role.team_member",
	viewer: "role.viewer",
};

const roleColors: Record<
	string,
	"accent" | "success" | "warning" | "danger" | "default"
> = {
	super_admin: "danger",
	admin: "accent",
	project_manager: "warning",
	team_member: "success",
	viewer: "default",
};

export const UserView: React.FC = () => {
	const { t } = useTranslation();
	const { id } = useParams<{ id: string }>();
	const navigate = useNavigate();
	const base = useLocation().pathname.startsWith("/admin")
		? "/admin/users"
		: "/users";
	const { showToast } = useToast();
	const canEditUsers = useCan('users.edit');
	const canDeleteUsers = useCan('users.delete');

	const [user, setUser] = useState<UserDetail | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [deleteModal, setDeleteModal] = useState(false);
	const [isDeleting, setIsDeleting] = useState(false);

	useEffect(() => {
		const fetchUser = async () => {
			try {
				const response = (await usersApi.getOne(Number(id))) as UserDetail;
				setUser(response);
			} catch {
				showToast("error", t("users.load_error"));
				navigate(base);
			} finally {
				setIsLoading(false);
			}
		};

		fetchUser();
	}, [id]);

	const handleDelete = async () => {
		if (!user) return;

		setIsDeleting(true);
		try {
			await usersApi.delete(user.id);
			showToast("success", t("users.delete_success"));
			navigate(base);
		} catch {
			showToast("error", t("users.delete_error"));
		} finally {
			setIsDeleting(false);
		}
	};

	if (isLoading) {
		return (
			<div className="space-y-6">
				<div className="flex items-center gap-4">
					<Skeleton className="h-20 w-20 rounded-full" />
					<div className="space-y-2">
						<Skeleton className="h-6 w-48" />
						<Skeleton className="h-4 w-32" />
					</div>
				</div>
				<Skeleton className="h-64 w-full" />
			</div>
		);
	}

	if (!user) {
		return (
			<div className="text-center py-12">
				<IconUser className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
				<h3 className="text-lg font-medium text-[var(--text-primary)]">
					{t("users.not_found")}
				</h3>
			</div>
		);
	}

	return (
		<div className="space-y-6">
			<PageHeader
				icon={IconUser}
				iconTone="admin"
				breadcrumb={
					<Breadcrumb
						items={[
							{ label: t("users.title"), href: base },
							{ label: user.name },
						]}
					/>
				}
				title={user.name}
				status={
					user.is_active ? (
						<Badge variant="success" size="sm">
							<IconCircleCheck className="h-3 w-3 me-1" />
							{t("common.active")}
						</Badge>
					) : (
						<Badge variant="danger" size="sm">
							<IconCircleX className="h-3 w-3 me-1" />
							{t("common.inactive")}
						</Badge>
					)
				}
				description={user.job_title || undefined}
				metadata={
					<>
						<span className="flex items-center gap-1">
							<IconMail className="h-4 w-4" />
							{user.email}
						</span>
						{user.phone && (
							<span className="flex items-center gap-1">
								<IconPhone className="h-4 w-4" />
								{user.phone}
								{user.extension && ` #${user.extension}`}
							</span>
						)}
					</>
				}
				actions={
					<>
						<Link to={`/users/${user.id}/access`}>
							<Button variant="ghost">
								{t("admin.access.title", "الصلاحيات")}
							</Button>
						</Link>
						{canEditUsers && (
							<Link to={`${base}/${user.id}/edit`}>
								<Button variant="outline">
									<IconEdit className="h-4 w-4 me-2" />
									{t("common.edit")}
								</Button>
							</Link>
						)}
						{canDeleteUsers && (
							<Button variant="danger" onClick={() => setDeleteModal(true)}>
								<IconTrash className="h-4 w-4 me-2" />
								{t("common.delete")}
							</Button>
						)}
					</>
				}
			/>

			{/* Tabs Content */}
			<Tabs defaultValue="info">
				<TabsList>
					<TabsTrigger value="info">{t("users.basic_info")}</TabsTrigger>
					<TabsTrigger value="projects">
						{t("users.projects")} ({user.managed_projects?.length || 0})
					</TabsTrigger>
					<TabsTrigger value="tasks">
						{t("users.tasks")} ({user.tasks?.length || 0})
					</TabsTrigger>
					<TabsTrigger value="permissions">
						{t("users.permissions")}
					</TabsTrigger>
					<TabsTrigger value="security">{t("users.security")}</TabsTrigger>
				</TabsList>

				<TabsContent value="info">
					<Card className="p-0">
						<CardContent className="p-6">
							<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("users.department")}
									</h3>
									{user.department ? (
										<div className="flex items-center gap-2 text-[var(--text-primary)]">
											<IconBuilding className="h-5 w-5 text-[var(--text-tertiary)]" />
											{user.department.name}
										</div>
									) : (
										<span className="text-[var(--text-tertiary)]">
											{t("common.not_specified")}
										</span>
									)}
								</div>

								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("users.roles")}
									</h3>
									<div className="flex flex-wrap gap-2">
										{user.roles.map((role) => (
											<Badge key={role} variant={roleColors[role] || "default"}>
												<IconShield className="h-3 w-3 me-1" />
												{t(roleLabels[role]) || role}
											</Badge>
										))}
									</div>
								</div>

								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("users.job_title")}
									</h3>
									<div className="flex items-center gap-2 text-[var(--text-primary)]">
										<IconBriefcase className="h-5 w-5 text-[var(--text-tertiary)]" />
										{user.job_title || t("common.not_specified")}
									</div>
								</div>

								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("common.created_at")}
									</h3>
									<div className="flex items-center gap-2 text-[var(--text-primary)]">
										<IconCalendar className="h-5 w-5 text-[var(--text-tertiary)]" />
										{formatDate(user.created_at)}
									</div>
								</div>

								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("common.created_by")}
									</h3>
									{user.creator ? (
										<div className="flex items-center gap-2 text-[var(--text-primary)]">
											<IconUserCheck className="h-5 w-5 text-[var(--text-tertiary)]" />
											<Link
												to={`${base}/${user.creator.id}`}
												className="hover:text-[var(--accent-default)]"
											>
												{user.creator.name}
											</Link>
										</div>
									) : (
										<span className="text-[var(--text-tertiary)]">
											{t("common.not_specified")}
										</span>
									)}
								</div>

								<div>
									<h3 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
										{t("common.last_updated_by")}
									</h3>
									{user.updater ? (
										<div className="flex items-center gap-2 text-[var(--text-primary)]">
											<IconUserCog className="h-5 w-5 text-[var(--text-tertiary)]" />
											<Link
												to={`${base}/${user.updater.id}`}
												className="hover:text-[var(--accent-default)]"
											>
												{user.updater.name}
											</Link>
											<span className="text-[var(--text-tertiary)] text-sm">
												({formatDate(user.updated_at)})
											</span>
										</div>
									) : (
										<span className="text-[var(--text-tertiary)]">
											{t("common.not_modified")}
										</span>
									)}
								</div>
							</div>
						</CardContent>
					</Card>
				</TabsContent>

				<TabsContent value="projects">
					<Card className="p-0">
						{user.managed_projects?.length > 0 ? (
							<div className="divide-y divide-[var(--border-default)]">
								{user.managed_projects.map((project) => (
									<div
										key={project.id}
										className="p-4 flex items-center justify-between"
									>
										<div className="flex items-center gap-3">
											<div className="h-10 w-10 rounded-lg bg-[var(--accent-subtle)] flex items-center justify-center">
												<IconLayoutKanban className="h-5 w-5 text-[var(--accent-default)]" />
											</div>
											<div>
												<Link
													to={`/projects/${project.id}`}
													className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
												>
													{project.name}
												</Link>
											</div>
										</div>
										<StatusBadge type="project" status={project.status} size="sm" />
									</div>
								))}
							</div>
						) : (
							<div className="p-12 text-center">
								<IconLayoutKanban className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
								<h3 className="text-lg font-medium text-[var(--text-primary)]">
									{t("users.no_projects")}
								</h3>
								<p className="text-[var(--text-tertiary)]">{t("users.no_projects_desc")}</p>
							</div>
						)}
					</Card>
				</TabsContent>

				<TabsContent value="tasks">
					<Card className="p-0">
						{user.tasks?.length > 0 ? (
							<div className="divide-y divide-[var(--border-default)]">
								{user.tasks.map((task) => (
									<div
										key={task.id}
										className="p-4 flex items-center justify-between"
									>
										<div className="flex items-center gap-3">
											<div className="h-10 w-10 rounded-lg bg-[var(--surface-muted)] flex items-center justify-center">
												<IconClipboardList className="h-5 w-5 text-[var(--accent-default)]" />
											</div>
											<div>
												<Link
													to={`/tasks/${task.id}`}
													className="font-medium text-[var(--text-primary)] hover:text-[var(--accent-default)]"
												>
													{task.title}
												</Link>
												<p className="text-sm text-[var(--text-tertiary)]">
													{task.project?.name}
												</p>
											</div>
										</div>
										<StatusBadge type="task" status={task.status} size="sm" />
									</div>
								))}
							</div>
						) : (
							<div className="p-12 text-center">
								<IconClipboardList className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
								<h3 className="text-lg font-medium text-[var(--text-primary)]">
									{t("users.no_tasks")}
								</h3>
								<p className="text-[var(--text-tertiary)]">{t("users.no_tasks_desc")}</p>
							</div>
						)}
					</Card>
				</TabsContent>

				<TabsContent value="permissions">
					<Card className="p-0">
						<CardContent className="p-6">
							{/* Phase 9 cutover: display canonical capabilities instead of legacy permissions. */}
							{(user.capabilities?.length ?? 0) > 0 ? (
								<div className="flex flex-wrap gap-2">
									{(user.capabilities ?? []).map((capability) => (
										<Badge key={capability} variant="default">
											{capability}
										</Badge>
									))}
								</div>
							) : (
								<div className="text-center py-8">
									<IconShield className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
									<h3 className="text-lg font-medium text-[var(--text-primary)]">
										{t("users.no_direct_permissions")}
									</h3>
									<p className="text-[var(--text-tertiary)]">
										{t("users.permissions_inherited")}
									</p>
								</div>
							)}
						</CardContent>
					</Card>
				</TabsContent>

				<TabsContent value="security">
					<UserSecurityCard userId={user.id} />
				</TabsContent>
			</Tabs>

			{/* Delete Modal */}
			<Modal
				isOpen={deleteModal}
				onClose={() => setDeleteModal(false)}
				title={t("users.delete_user")}
			>
				<p className="text-[var(--text-secondary)] mb-6">
					{t("users.delete_confirm", { name: user.name })}
				</p>
				<div className="flex gap-3 justify-end">
					<Button variant="outline" onClick={() => setDeleteModal(false)}>
						{t("common.cancel")}
					</Button>
					<Button variant="danger" onClick={handleDelete} loading={isDeleting}>
						<IconTrash className="h-4 w-4 me-2" />
						{t("common.delete")}
					</Button>
				</div>
			</Modal>
		</div>
	);
};

export default UserView;
