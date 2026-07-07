/**
 * Incident (OVR) entity — API. تقارير الحوادث
 */

import { api } from "@shared/api/client";

// Incident Categories API (تصنيفات الحوادث)
export const incidentCategoriesApi = {
	getAll: (params?: Record<string, string>) => {
		const query = params ? "?" + new URLSearchParams(params).toString() : "";
		return api.get(`/ovr/categories${query}`);
	},
	getList: () => api.get("/ovr/categories/list"),
	getStats: () => api.get("/ovr/categories/stats"),
	getOne: (id: number | string) => api.get(`/ovr/categories/${id}`),
	create: (data: any) => api.post("/ovr/categories", data),
	update: (id: number | string, data: any) =>
		api.put(`/ovr/categories/${id}`, data),
	delete: (id: number | string) => api.delete(`/ovr/categories/${id}`),
};

// Incidents API (الحوادث)
export const incidentsApi = {
	getAll: (params?: Record<string, string>) => {
		const query = params ? "?" + new URLSearchParams(params).toString() : "";
		return api.get(`/ovr/incidents${query}`);
	},
	getRecent: (limit?: number) => {
		const query = limit ? `?limit=${limit}` : "";
		return api.get(`/ovr/incidents/recent${query}`);
	},
	getStats: (params?: Record<string, string>) => {
		const query = params ? "?" + new URLSearchParams(params).toString() : "";
		return api.get(`/ovr/incidents/stats${query}`);
	},
	getOne: (report: string) => api.get(`/ovr/incidents/${report}`),
	// Departments the user may target when creating a report (scoped picker).
	getCreatableDepartments: () => api.get("/ovr/incidents/creatable-departments"),
	create: (data: any) => api.post("/ovr/incidents", data),
	update: (report: string, data: any) =>
		api.put(`/ovr/incidents/${report}`, data),
	delete: (report: string) => api.delete(`/ovr/incidents/${report}`),
	updateStatus: (
		report: string | number,
		dataOrStatus:
			| {
					status: string;
					reason: string;
					assigned_to?: number | null;
					closure_reason?: string | null;
			  }
			| string,
		notes?: string,
	) =>
		api.patch(
			`/ovr/incidents/${report}/status`,
			typeof dataOrStatus === "string"
				? { status: dataOrStatus, notes: notes ?? "" }
				: dataOrStatus,
		),
	submit: (report: string) => api.post(`/ovr/incidents/${report}/submit`),
	addAction: (report: string | number, data: any) =>
		api.post(`/ovr/incidents/${report}/actions`, data),
	addWitness: (report: string | number, data: any) =>
		api.post(`/ovr/incidents/${report}/witnesses`, data),
	getComments: (report: string) => api.get(`/ovr/incidents/${report}/comments`),
	addComment: (report: string, data: { content: string }) =>
		api.post(`/ovr/incidents/${report}/comments`, data),
	// Participants (invite any employee to a report).
	addParticipant: (report: string, userId: number) =>
		api.post(`/ovr/incidents/${report}/participants`, { user_id: userId }),
	removeParticipant: (report: string, userId: number) =>
		api.delete(`/ovr/incidents/${report}/participants/${userId}`),
	getAudit: (report: string) => api.get(`/ovr/incidents/${report}/audit`),
	exportUrl: (
		format: "csv" | "pdf" = "csv",
		params?: Record<string, string>,
	) => {
		const query = new URLSearchParams({ ...(params ?? {}), format }).toString();
		return `/api/ovr/incidents/export?${query}`;
	},
};

// OVR admin settings (governing department) — admin-gated server-side.
export const ovrSettingsApi = {
	getGoverningDepartment: () => api.get("/ovr/settings/governing-department"),
	updateGoverningDepartment: (departmentId: number | null) =>
		api.put("/ovr/settings/governing-department", {
			department_id: departmentId,
		}),
};

// Public report tracking (no authentication required)
// `trackingToken` is the per-report random token shipped in the reporter's
// notification email/SMS, NOT the enumerable report_number.
export const publicTrackApi = {
	track: (trackingToken: string) =>
		api.get(`/ovr/track/${encodeURIComponent(trackingToken)}`),
};
