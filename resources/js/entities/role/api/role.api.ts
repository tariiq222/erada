/**
 * Role entity — API (إدارة الأدوار والصلاحيات).
 */

import { api } from '@shared/api/client';
import type { Role, PermissionMatrix, AbilityRegistry } from '../model/role';

export const rolesApi = {
  list: () => api.get<{ data: Role[]; meta: { total: number } }>('/roles'),
  get: (id: number) => api.get<{ data: Role }>(`/roles/${id}`),
  create: (data: { name: string; scope_type?: string; permissions?: string[]; label_ar?: string; label_en?: string; permissions_capabilities?: string[]; reach?: Record<string, string> }) =>
    api.post('/roles', data),
  update: (id: number, data: { name?: string; scope_type?: string; permissions?: string[]; label_ar?: string; label_en?: string; permissions_capabilities?: string[]; reach?: Record<string, string> }) =>
    api.put(`/roles/${id}`, data),
  delete: (id: number) => api.delete(`/roles/${id}`),
  permissions: () =>
    api.get<{ data: PermissionMatrix }>('/roles/permissions'),
  abilities: () =>
    api.get<{ data: AbilityRegistry }>('/roles/abilities'),
  scopeOptions: () =>
    api.get<{
      scopes: { key: string; label: string }[];
      definitions: Record<string, { role_key: string; label: string }[]>;
    }>('/roles/scope-options'),
  assignToUser: (data: { user_id: number; roles: string[] }) =>
    api.post('/roles/assign', data),
};

/** A governing-department rule row (unified governance_rules screen). */
export interface GovernanceRuleRow {
  resource_type: string;
  label: string;
  governing_unit_id: number | null;
  governing_unit_name: string | null;
  applies_to_children: boolean;
}

export const governanceRulesApi = {
  list: () => api.get<{ data: GovernanceRuleRow[] }>('/governance-rules'),
  update: (data: { resource_type: string; resource_subtype?: string | null; governing_unit_id: number | null }) =>
    api.put('/governance-rules', data),
};

/** A user's "why do they have access" summary. */
export interface AccessSummary {
  functional_roles: string[];
  scoped: {
    role: string;
    label: string;
    scope_type: string;
    scope_id: number;
    scope_name: string | null;
    source: string;
    reach: Record<string, string>;
  }[];
}

export const scopedRolesApi = {
  accessSummary: (userId: number) =>
    api.get<{ data: AccessSummary }>(`/scoped-roles/user/${userId}/access-summary`),
};
