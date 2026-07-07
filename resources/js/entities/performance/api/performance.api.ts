/**
 * Performance entity — KPI API.
 */
import { api } from '@shared/api/client';

type QueryParams = Record<string, string | number | null | undefined>;

const toQuery = (params?: QueryParams) => {
  if (!params) return '';

  const entries = Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '');
  if (entries.length === 0) return '';

  return `?${new URLSearchParams(entries.map(([key, value]) => [key, String(value)])).toString()}`;
};

export type PerformanceKPIStatus = 'active' | 'inactive' | 'archived';
export type PerformanceKPIFrequency = 'daily' | 'weekly' | 'monthly' | 'quarterly' | 'yearly';
export type PerformanceKPIDirection = 'increase' | 'decrease' | 'maintain';
export type PerformanceStatus = 'on_track' | 'at_risk' | 'off_track';
export type PerformanceKPILinkableType = 'project' | 'program' | 'objective' | 'review' | 'department';

export interface PerformanceUserSummary {
  id: number;
  name: string;
}

export interface PerformanceKPIListParams extends QueryParams {
  page?: number | string;
  per_page?: number | string;
  search?: string;
  status?: PerformanceKPIStatus | string;
  category?: string;
}

export interface PerformanceKPILink {
  id: number;
  kpi_id?: number;
  linkable_id: number | string;
  linkable_type?: string;
  relationship_type?: string | null;
  weight?: number | string | null;
  notes?: string | null;
  linkable?: {
    id: number | string;
    name?: string;
    code?: string;
    title?: string;
  } | null;
  creator?: PerformanceUserSummary | null;
  created_at?: string;
}

export interface PerformanceMeasurement {
  id: number;
  kpi_id?: number;
  value: number | string;
  measurement_date: string;
  notes?: string | null;
  evidence_url?: string | null;
  source_type?: string | null;
  source_id?: number | null;
  recorder?: PerformanceUserSummary | null;
  created_at?: string;
}

export interface PerformanceKPI {
  id: number;
  code?: string | null;
  name: string;
  description?: string | null;
  measurement_method?: string | null;
  category?: string | null;
  baseline?: number | string | null;
  target: number | string | null;
  current_value?: number | string | null;
  unit?: string | null;
  frequency?: PerformanceKPIFrequency | string | null;
  direction?: PerformanceKPIDirection | string | null;
  status?: PerformanceKPIStatus | string | null;
  owner_id?: number | null;
  owner?: PerformanceUserSummary | null;
  creator?: PerformanceUserSummary | null;
  order?: number | null;
  achievement_percentage?: number | null;
  performance_status?: PerformanceStatus | string | null;
  frequency_label?: string | null;
  direction_label?: string | null;
  measurements?: PerformanceMeasurement[];
  links?: PerformanceKPILink[];
  created_at?: string;
  updated_at?: string;
}

export interface PerformanceKPIListResponse {
  data: PerformanceKPI[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export type PerformanceKPIExportFormat = 'csv' | 'xlsx';

export interface PerformanceKPIImportSummary {
  created: number;
  updated: number;
  skipped: number;
  errors: Array<{
    row: number;
    messages: string[];
  }>;
}

export interface CreatePerformanceKPIRequest {
  organization_id?: number;
  code?: string;
  name: string;
  description?: string;
  measurement_method?: string;
  category?: string;
  baseline?: number | string | null;
  target?: number | string | null;
  current_value?: number | string | null;
  unit?: string;
  frequency?: PerformanceKPIFrequency | string;
  direction?: PerformanceKPIDirection | string;
  status?: PerformanceKPIStatus | string;
  owner_id?: number | null;
  order?: number | string | null;
  department_ids?: number[];
}

export type UpdatePerformanceKPIRequest = Partial<CreatePerformanceKPIRequest>;

export interface CreatePerformanceKPIResponse {
  message: string;
  kpi: PerformanceKPI;
}

export interface CreatePerformanceMeasurementRequest {
  value: number | string;
  measurement_date: string;
  notes?: string;
  evidence_url?: string;
  source_type?: string;
  source_id?: number;
}

export interface CreatePerformanceMeasurementResponse {
  message: string;
  measurement: PerformanceMeasurement;
  kpi?: PerformanceKPI;
}

export interface PerformanceMeasurementListResponse {
  data: PerformanceMeasurement[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface CreatePerformanceKPILinkRequest {
  linkable_type: PerformanceKPILinkableType | string;
  linkable_id: number;
  relationship_type?: 'primary' | string;
  weight?: number | string | null;
  notes?: string;
}

export interface CreatePerformanceKPILinkResponse {
  message: string;
  link: PerformanceKPILink;
}

export interface PerformanceKPILinkListResponse {
  data: PerformanceKPILink[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export const performanceApi = {
  listKPIs: (params?: PerformanceKPIListParams) =>
    api.get<PerformanceKPIListResponse>(`/performance/kpis${toQuery(params)}`),
  exportKPIs: (format: PerformanceKPIExportFormat, params?: PerformanceKPIListParams) =>
    api.blob(`/performance/kpis/export/${format}${toQuery(params)}`),
  importKPIs: (file: File, organizationId?: number) => {
    const formData = new FormData();
    formData.append('file', file);
    if (organizationId !== undefined) {
      formData.append('organization_id', String(organizationId));
    }

    return api.post<PerformanceKPIImportSummary>('/performance/kpis/import', formData);
  },
  listContextKPIs: (contextType: PerformanceKPILinkableType | string, contextId: number) =>
    api.get<PerformanceKPIListResponse>(`/performance/context/${contextType}/${contextId}/kpis`),
  getKPI: (id: number) => api.get<PerformanceKPI>(`/performance/kpis/${id}`),
  createKPI: (data: CreatePerformanceKPIRequest) =>
    api.post<CreatePerformanceKPIResponse>('/performance/kpis', data),
  updateKPI: (id: number, data: UpdatePerformanceKPIRequest) =>
    api.put<CreatePerformanceKPIResponse>(`/performance/kpis/${id}`, data),
  deleteKPI: (id: number) => api.delete<{ message: string }>(`/performance/kpis/${id}`),
  listMeasurements: (kpiId: number, params?: QueryParams) =>
    api.get<PerformanceMeasurementListResponse>(`/performance/kpis/${kpiId}/measurements${toQuery(params)}`),
  createMeasurement: (kpiId: number, data: CreatePerformanceMeasurementRequest) =>
    api.post<CreatePerformanceMeasurementResponse>(`/performance/kpis/${kpiId}/measurements`, data),
  listLinks: (kpiId: number, params?: QueryParams) =>
    api.get<PerformanceKPILinkListResponse>(`/performance/kpis/${kpiId}/links${toQuery(params)}`),
  createLink: (kpiId: number, data: CreatePerformanceKPILinkRequest) =>
    api.post<CreatePerformanceKPILinkResponse>(`/performance/kpis/${kpiId}/links`, data),
  deleteLink: (kpiId: number, linkId: number) =>
    api.delete<{ message: string }>(`/performance/kpis/${kpiId}/links/${linkId}`),
};
