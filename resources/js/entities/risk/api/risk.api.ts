/**
 * Risk entity — API.
 */
import { api } from "@shared/api/client";
import type { ImpactTypeSettingsPayload, RiskTypeSettingsPayload } from "../model/risk";

export const risksApi = {
  list: (params?: Record<string, string | number>) => {
    const query = params ? "?" + new URLSearchParams(params as Record<string, string>).toString() : "";
    return api.get(`/risk-management/risks${query}`);
  },
  get: (id: number | string) => api.get(`/risk-management/risks/${id}`),
  // Departments the user may target when creating a risk (scoped picker).
  getCreatableDepartments: () => api.get("/risk-management/risks/creatable-departments"),
  // Admin: governing department for risks.
  getGoverningDepartment: () => api.get("/risk-management/settings/governing-department"),
  updateGoverningDepartment: (departmentId: number | null) =>
    api.put("/risk-management/settings/governing-department", { department_id: departmentId }),
  create: (data: Record<string, unknown>) => api.post("/risk-management/risks", data),
  update: (id: number | string, data: Record<string, unknown>) =>
    api.put(`/risk-management/risks/${id}`, data),
  remove: (id: number | string) => api.delete(`/risk-management/risks/${id}`),
  reassess: (id: number | string, data: Record<string, unknown>) =>
    api.post(`/risk-management/risks/${id}/assessments`, data),
  changeStatus: (id: number | string, data: { to_status: string; reason?: string }) =>
    api.post(`/risk-management/risks/${id}/status-changes`, data),
  statusHistory: (id: number | string) =>
    api.get(`/risk-management/risks/${id}/status-changes`),
  addAction: (id: number | string, data: Record<string, unknown>) =>
    api.post(`/risk-management/risks/${id}/actions`, data),
  getAction: (actionId: number | string) => api.get(`/risk-management/actions/${actionId}`),
  updateAction: (actionId: number | string, data: Record<string, unknown>) =>
    api.put(`/risk-management/actions/${actionId}`, data),
  removeAction: (actionId: number | string) =>
    api.delete(`/risk-management/actions/${actionId}`),
  addActionUpdate: (actionId: number | string, data: Record<string, unknown>) =>
    api.post(`/risk-management/actions/${actionId}/updates`, data),
  listActionUpdates: (actionId: number | string) =>
    api.get(`/risk-management/actions/${actionId}/updates`),
  settings: () => api.get("/risk-management/settings"),
  createRiskType: (data: RiskTypeSettingsPayload) =>
    api.post("/risk-management/risk-types", data),
  updateRiskType: (id: number | string, data: RiskTypeSettingsPayload) =>
    api.put(`/risk-management/risk-types/${id}`, data),
  removeRiskType: (id: number | string) =>
    api.delete(`/risk-management/risk-types/${id}`),
  createImpactType: (data: ImpactTypeSettingsPayload) =>
    api.post("/risk-management/impact-types", data),
  updateImpactType: (id: number | string, data: ImpactTypeSettingsPayload) =>
    api.put(`/risk-management/impact-types/${id}`, data),
  removeImpactType: (id: number | string) =>
    api.delete(`/risk-management/impact-types/${id}`),
};

export const risksDashboardApi = {
  get: () => api.get("/risk-management/dashboard"),
  getMatrix: () => api.get("/risk-management/matrix"),
  exportUrl: (format: "csv" | "pdf" = "csv") =>
    `/api/risk-management/export/${format}`,
};
