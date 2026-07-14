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
  /**
   * Canonical role-assignment write — `/api/roles/assign`.
   *
   * Used by `super_admin` and any curated admin who holds
   * `core.assign_roles`. The OrgSuper-specific path
   * (`/api/org-super/role-assignments`) is reached via
   * `rolesApi.orgSuperAssignToUser` below — DO NOT collapse the two;
   * the BE narrows the OrgSuper route with a dedicated actor guard
   * and engine-capability gate that the canonical route does NOT
   * enforce.
   */
  assignToUser: (data: AuthorizationRoleAssignmentRequest) =>
    api.post<AuthorizationRoleAssignmentResponse>('/roles/assign', data),
  /**
   * Organization Super Admin role-assignment write —
   * `POST /api/org-super/role-assignments`.
   *
   * Mirrors the payload of `assignToUser` (same canonical
   * `AuthorizationRoleAssignmentRequest` envelope) but routes through
   * the narrow OrgSuper-only endpoint. Server enforcement:
   *   - actor MUST be `is_organization_super_admin` and NOT super_admin,
   *   - subject MUST belong to actor's organization,
   *   - role MUST be active, NOT `is_admin_role`, NOT `is_system`,
   *     and NOT named `super_admin` / `organization_super_admin` / `admin`,
   *   - assignment MUST be `scope_type=organization`,
   *     `scope_id=actor.organization_id`, `inherit_to_children=false`.
   *
   * The FE filters the role picker via
   * `filterAssignableRolesForOrgSuper` and pins
   * `scope_type`/`scope_id`/`inherit_to_children` to those values so
   * the actor never submits a payload the BE will reject.
   */
  orgSuperAssignToUser: (data: AuthorizationRoleAssignmentRequest) =>
    api.post<AuthorizationRoleAssignmentResponse>('/org-super/role-assignments', data),
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
