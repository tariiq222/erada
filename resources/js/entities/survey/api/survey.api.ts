/**
 * Survey entity — API.
 */
import { api } from "@shared/api/client";

import type { CreateSurveyRequest, CreateFieldRequest } from "../model/survey";

const buildQueryString = (params?: Record<string, string>): string => {
  if (!params) return '';
  const query = new URLSearchParams(params).toString();
  return query ? `?${query}` : '';
};

export const surveysApi = {
  // الاستبيانات
  getAll: (params?: Record<string, string>) =>
    api.get(`/surveys${buildQueryString(params)}`),

  getStats: () => api.get('/surveys/stats'),

  getById: (id: number) =>
    api.get(`/surveys/${id}`),

  create: (data: CreateSurveyRequest) =>
    api.post('/surveys', data),

  update: (id: number, data: Partial<CreateSurveyRequest>) =>
    api.put(`/surveys/${id}`, data),

  delete: (id: number) =>
    api.delete(`/surveys/${id}`),

  publish: (id: number) =>
    api.post(`/surveys/${id}/publish`),

  close: (id: number, reason?: string) =>
    api.post(`/surveys/${id}/close`, { reason }),

  createNewRevision: (id: number) =>
    api.post(`/surveys/${id}/new-revision`),

  getRevisions: (id: number) =>
    api.get(`/surveys/${id}/revisions`),

  getAnalytics: (id: number) =>
    api.get(`/surveys/${id}/analytics`),

  // الحقول
  getFields: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/fields`),

  addField: (surveyId: number, data: CreateFieldRequest) =>
    api.post(`/surveys/${surveyId}/fields`, data),

  updateField: (surveyId: number, fieldId: number, data: Partial<CreateFieldRequest>) =>
    api.put(`/surveys/${surveyId}/fields/${fieldId}`, data),

  deleteField: (surveyId: number, fieldId: number) =>
    api.delete(`/surveys/${surveyId}/fields/${fieldId}`),

  reorderFields: (surveyId: number, fieldIds: number[]) =>
    api.post(`/surveys/${surveyId}/fields/reorder`, { fields: fieldIds }),

  // الأقسام
  getSections: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/sections`),

  addSection: (surveyId: number, data: { title: string; description?: string }) =>
    api.post(`/surveys/${surveyId}/sections`, data),

  updateSection: (surveyId: number, sectionId: number, data: { title?: string; description?: string }) =>
    api.put(`/surveys/${surveyId}/sections/${sectionId}`, data),

  deleteSection: (surveyId: number, sectionId: number) =>
    api.delete(`/surveys/${surveyId}/sections/${sectionId}`),

  reorderSections: (surveyId: number, sectionIds: number[]) =>
    api.post(`/surveys/${surveyId}/sections/reorder`, { sections: sectionIds }),

  // الإجابات
  getResponses: (surveyId: number, params?: Record<string, string>) =>
    api.get(`/surveys/${surveyId}/responses${buildQueryString(params)}`),

  getResponse: (surveyId: number, responseId: number) =>
    api.get(`/surveys/${surveyId}/responses/${responseId}`),

  flagResponse: (surveyId: number, responseId: number, notes: string) =>
    api.post(`/surveys/${surveyId}/responses/${responseId}/flag`, { notes }),

  reviewResponse: (surveyId: number, responseId: number, data: { status: string; notes?: string }) =>
    api.post(`/surveys/${surveyId}/responses/${responseId}/review`, data),

  // Cluster aggregate stats — same response surface as the BE
  // `/api/surveys/{id}/cluster-stats` JSON envelope (Phase CFA-10 +
  // Phase 3A grouping by respondent_organization_id snapshot).
  // No raw responses are ever exposed.
  getClusterStats: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/cluster-stats`),

  // Phase 4D — cluster aggregate direct download.
  //
  // Returns the BE blob (CSV / JSON text/csv;charset=UTF-8 /
  // application/json;charset=UTF-8 with Content-Disposition:
  // attachment; filename="..."). The BE Phase 3B contract is
  // strict: NO `storage/app/private/exports` writes happen on this
  // path. The response is streamed directly to the wire; the FE
  // pulls the responseType:'blob' shape via api.blob() and triggers
  // the browser download via URL.createObjectURL.
  downloadClusterExport: (surveyId: number, format: 'csv' | 'json' = 'csv') =>
    api.blob(`/surveys/${surveyId}/cluster-export?format=${format}`),

  exportResponses: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/export`),

  // الدعوات
  getInvitations: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/invitations`),

  createInvitation: (surveyId: number, data: { email: string; name?: string; expires_at?: string }) =>
    api.post(`/surveys/${surveyId}/invitations`, data),

  bulkCreateInvitations: (surveyId: number, data: { invitations: Array<{ email: string; name?: string }> }) =>
    api.post(`/surveys/${surveyId}/invitations/bulk`, data),

  revokeInvitation: (surveyId: number, invitationId: number) =>
    api.post(`/surveys/${surveyId}/invitations/${invitationId}/revoke`),

  resendInvitation: (surveyId: number, invitationId: number) =>
    api.post(`/surveys/${surveyId}/invitations/${invitationId}/resend`),

  // قوالب الربط
  getMappings: (surveyId: number) =>
    api.get(`/surveys/${surveyId}/mappings`),

  createMapping: (surveyId: number, data: any) =>
    api.post(`/surveys/${surveyId}/mappings`, data),

  updateMapping: (surveyId: number, templateId: number, data: any) =>
    api.put(`/surveys/${surveyId}/mappings/${templateId}`, data),

  deleteMapping: (surveyId: number, templateId: number) =>
    api.delete(`/surveys/${surveyId}/mappings/${templateId}`),

  getAvailableTargets: () =>
    api.get('/surveys/mapping-targets'),
};

// طلبات الاستيراد
export const dataImportsApi = {
  getAll: (params?: Record<string, string>) =>
    api.get(`/data-imports${buildQueryString(params)}`),

  getById: (id: number) =>
    api.get(`/data-imports/${id}`),

  approve: (id: number) =>
    api.post(`/data-imports/${id}/approve`),

  reject: (id: number, reason: string) =>
    api.post(`/data-imports/${id}/reject`, { reason }),

  apply: (id: number) =>
    api.post(`/data-imports/${id}/apply`),

  bulkApprove: (ids: number[]) =>
    api.post('/data-imports/bulk-approve', { ids }),

  bulkReject: (ids: number[], reason: string) =>
    api.post('/data-imports/bulk-reject', { ids, reason }),
};

// الاستبيانات العامة (بدون مصادقة)
export const publicSurveysApi = {
  getByCode: (code: string, revision?: number) =>
    api.get(`/surveys/public/${code}${revision ? `?rev=${revision}` : ''}`),

  submit: (code: string, data: { answers: Record<string, any>; version_hash: string; respondent?: any; fingerprint?: string }) =>
    api.post(`/surveys/public/${code}/submit`, data),

  getByInvitation: (token: string) =>
    api.get(`/surveys/public/invitation/${token}`),

  submitByInvitation: (token: string, data: { answers: Record<string, any>; version_hash: string; fingerprint?: string }) =>
    api.post(`/surveys/public/invitation/${token}/submit`, data),
};
