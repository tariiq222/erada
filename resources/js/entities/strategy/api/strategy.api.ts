/**
 * Strategy entity — API.
 *
 * PMI Standard: Portfolio -> Program -> Project
 */
import { api } from "@shared/api/client";

import type {
  PortfolioStatus,
  PortfolioTreeParams,
  PortfolioTreePayload,
  PortfolioTreeResponse,
} from "../model/strategy";

// ========================================
// Strategy Dashboard API
// ========================================

export const strategyDashboardApi = {
  getSummary: () => api.get('/strategy/dashboard/summary'),
  getGoldenChain: (type: string, id: number) =>
    api.get(`/strategy/dashboard/golden-chain/${type}/${id}`),
  /**
   * Phase 7.2 — full tree view of a portfolio (portfolio → programs → projects).
   * Returns the unwrapped inner payload (consumers do not need to drill `.data`).
   */
  getPortfolioTree: async (
    portfolioId: number | string,
    params?: PortfolioTreeParams,
  ): Promise<PortfolioTreePayload> => {
    const queryString = params
      ? '?' +
        new URLSearchParams(
          Object.entries(params)
            .filter(([, v]) => v !== undefined)
            .map(([k, v]) => [k, String(v)]),
        ).toString()
      : '';
    const { data } = await api.get<PortfolioTreeResponse>(
      `/strategy/dashboard/portfolio/${portfolioId}/tree${queryString}`,
    );
    return data;
  },
};

// ========================================
// Portfolios API (المحافظ / الالتزامات التنفيذية)
// ========================================

export const portfoliosApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/portfolios${query}`);
  },
  getList: () => api.get('/strategy/portfolios/list'),
  getSummary: () => api.get('/strategy/portfolios/summary'),
  getOne: (id: number) => api.get(`/strategy/portfolios/${id}`),
  create: (data: unknown) => api.post('/strategy/portfolios', data),
  update: (id: number, data: unknown) => api.put(`/strategy/portfolios/${id}`, data),
  delete: (id: number) => api.delete(`/strategy/portfolios/${id}`),
  updatePriority: (id: number, data: { priority_rank: number; weight: number }) =>
    api.put(`/strategy/portfolios/${id}/priority`, data),
  updateStrategicStatus: (id: number, data: { portfolio_status: PortfolioStatus; decision_note?: string }) =>
    api.put(`/strategy/portfolios/${id}/strategic-status`, data),
};

// ========================================
// Programs API (المبادرات)
// PMI Standard: Portfolio -> Program -> Project
// ========================================

export const programsApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/programs${query}`);
  },
  getList: (portfolioId?: number) => {
    const query = portfolioId ? `?portfolio_id=${portfolioId}` : '';
    return api.get(`/strategy/programs/list${query}`);
  },
  getUnlinkedProjects: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/programs/unlinked-projects${query}`);
  },
  getOne: (id: number) => api.get(`/strategy/programs/${id}`),
  create: (data: unknown) => api.post('/strategy/programs', data),
  update: (id: number, data: unknown) => api.put(`/strategy/programs/${id}`, data),
  delete: (id: number) => api.delete(`/strategy/programs/${id}`),
  linkProject: (programId: number, projectId: number) =>
    api.post(`/strategy/programs/${programId}/link-project`, { project_id: projectId }),
  unlinkProject: (programId: number, projectId: number) =>
    api.delete(`/strategy/programs/${programId}/unlink-project/${projectId}`),
};

// ========================================
// Blockers API (التعثرات)
// ========================================

export const blockersApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/blockers${query}`);
  },
  getOne: (id: number) => api.get(`/strategy/blockers/${id}`),
  create: (data: unknown) => api.post('/strategy/blockers', data),
  update: (id: number, data: unknown) => api.put(`/strategy/blockers/${id}`, data),
  delete: (id: number) => api.delete(`/strategy/blockers/${id}`),
  resolve: (id: number, resolution: string) =>
    api.post(`/strategy/blockers/${id}/resolve`, { resolution }),
  escalate: (id: number) => api.post(`/strategy/blockers/${id}/escalate`),
};

// ========================================
// Decisions API (القرارات)
// ========================================

export const decisionsApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/decisions${query}`);
  },
  getOne: (id: number) => api.get(`/strategy/decisions/${id}`),
  create: (data: unknown) => api.post('/strategy/decisions', data),
  update: (id: number, data: unknown) => api.put(`/strategy/decisions/${id}`, data),
  delete: (id: number) => api.delete(`/strategy/decisions/${id}`),
  approve: (id: number) => api.post(`/strategy/decisions/${id}/approve`),
  reject: (id: number, rationale?: string) =>
    api.post(`/strategy/decisions/${id}/reject`, { rationale }),
  defer: (id: number) => api.post(`/strategy/decisions/${id}/defer`),
};

// ========================================
// Reviews API (المراجعات PDCA)
// ========================================

export const reviewsApi = {
  getAll: (params?: Record<string, string>) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return api.get(`/strategy/reviews${query}`);
  },
  getOne: (id: number) => api.get(`/strategy/reviews/${id}`),
  create: (data: unknown) => api.post('/strategy/reviews', data),
  update: (id: number, data: unknown) => api.put(`/strategy/reviews/${id}`, data),
  delete: (id: number) => api.delete(`/strategy/reviews/${id}`),
};
