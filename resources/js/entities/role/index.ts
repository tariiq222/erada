/**
 * Role entity — public API.
 */
export * from './model/role';
export { rolesApi, governanceRulesApi, scopedRolesApi } from './api/role.api';
export type { GovernanceRuleRow, AccessSummary } from './api/role.api';
