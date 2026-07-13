/**
 * Role entity — API (إدارة الأدوار والصلاحيات).
 */

import { api } from '@shared/api/client';
import type {
  AbilityRegistry,
  AuthorizationRoleAssignmentRequest,
  AuthorizationRoleAssignmentResponse,
  Role,
  RoleWrite,
} from '../model/role';

export const rolesApi = {
  list: () => api.get<{ data: Role[]; meta: { total: number } }>('/roles'),
  get: (id: number) => api.get<{ data: Role }>(`/roles/${id}`),
  create: (data: RoleWrite) => api.post<{ data: Role }>('/roles', data),
  update: (id: number, data: RoleWrite) => api.put<{ data: Role }>(`/roles/${id}`, data),
  delete: (id: number) => api.delete(`/roles/${id}`),
  abilities: () =>
    api.get<{ data: AbilityRegistry }>('/roles/abilities'),
  scopeOptions: () =>
    api.get<{
      scopes: { key: string; label: string }[];
    }>('/roles/scope-options'),
  assignToUser: (data: AuthorizationRoleAssignmentRequest) =>
    api.post<AuthorizationRoleAssignmentResponse>('/roles/assign', data),
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
