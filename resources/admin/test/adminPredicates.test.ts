import { describe, expect, it } from 'vitest';
import {
  PROTECTED_ADMIN_ROLES,
  canExportActivityLogs,
  canMutateTargetLifecycle,
  isOrganizationSuperAdmin,
  isPlatformSuperAdmin,
  isProtectedAdminTarget,
  isSameOrgAsActor,
  ORG_SUPER_ADMIN_ROLE,
  PLATFORM_SUPER_ADMIN_ROLE,
} from '@admin/model/adminPredicates';
import type { AdminUser } from '@admin/model/admin';

const baseUser: Pick<AdminUser, 'roles' | 'organization_id'> = {
  roles: ['viewer'],
  organization_id: 17,
};

describe('adminPredicates', () => {
  it('exposes the canonical protected role names', () => {
    expect(PLATFORM_SUPER_ADMIN_ROLE).toBe('super_admin');
    expect(ORG_SUPER_ADMIN_ROLE).toBe('organization_super_admin');
    expect(PROTECTED_ADMIN_ROLES).toEqual(['super_admin', 'organization_super_admin']);
  });

  it('classifies platform and organization super admins by their role names', () => {
    expect(isPlatformSuperAdmin({ roles: ['super_admin'] })).toBe(true);
    expect(isPlatformSuperAdmin({ roles: ['organization_super_admin'] })).toBe(false);
    expect(isOrganizationSuperAdmin({ roles: ['organization_super_admin'] })).toBe(true);
    expect(isOrganizationSuperAdmin({ roles: ['super_admin'] })).toBe(false);
    expect(isOrganizationSuperAdmin({ roles: [] })).toBe(false);
    expect(isPlatformSuperAdmin({ roles: [] })).toBe(false);
    expect(isPlatformSuperAdmin(null)).toBe(false);
    expect(isOrganizationSuperAdmin(undefined)).toBe(false);
  });

  it('flags any target holding a protected admin role as locked', () => {
    expect(isProtectedAdminTarget({ roles: ['super_admin'] })).toBe(true);
    expect(isProtectedAdminTarget({ roles: ['organization_super_admin'] })).toBe(true);
    expect(isProtectedAdminTarget({ roles: ['viewer'] })).toBe(false);
    expect(isProtectedAdminTarget({ roles: [] })).toBe(false);
    expect(isProtectedAdminTarget(null)).toBe(false);
    expect(isProtectedAdminTarget({ roles: ['viewer', 'super_admin'] })).toBe(true);
  });

  it('treats same-org rows as org-aligned only when both ids match', () => {
    const actor = { organization_id: 17 };
    expect(isSameOrgAsActor(actor, { organization_id: 17 })).toBe(true);
    expect(isSameOrgAsActor(actor, { organization_id: 18 })).toBe(false);
    expect(isSameOrgAsActor(actor, { organization_id: null })).toBe(false);
    expect(isSameOrgAsActor({ organization_id: null }, { organization_id: 17 })).toBe(false);
    expect(isSameOrgAsActor(null, { organization_id: 17 })).toBe(false);
    expect(isSameOrgAsActor(actor, null)).toBe(false);
  });

  it('lets the platform super admin mutate any non-protected target', () => {
    const actor = { is_super_admin: true };
    expect(canMutateTargetLifecycle(actor, baseUser)).toBe(true);
    expect(canMutateTargetLifecycle(actor, { ...baseUser, organization_id: 99 })).toBe(true);
    expect(
      canMutateTargetLifecycle(actor, { ...baseUser, roles: ['super_admin'] }),
    ).toBe(false);
    expect(
      canMutateTargetLifecycle(actor, { ...baseUser, roles: ['organization_super_admin'] }),
    ).toBe(false);
  });

  it('restricts OrgSuper lifecycle actions to ordinary same-org users', () => {
    const actor = { is_organization_super_admin: true, organization_id: 17 };
    expect(canMutateTargetLifecycle(actor, baseUser)).toBe(true);
    expect(canMutateTargetLifecycle(actor, { ...baseUser, organization_id: 18 })).toBe(false);
    expect(
      canMutateTargetLifecycle(actor, { ...baseUser, roles: ['super_admin'] }),
    ).toBe(false);
    expect(
      canMutateTargetLifecycle(actor, {
        ...baseUser,
        roles: ['organization_super_admin'],
      }),
    ).toBe(false);
  });

  it('denies mutations when the actor has neither super nor org-super flag', () => {
    const actor = { organization_id: 17 };
    expect(canMutateTargetLifecycle(actor, baseUser)).toBe(false);
  });

  it('denies mutations when the target is missing', () => {
    expect(canMutateTargetLifecycle({ is_super_admin: true }, null)).toBe(false);
    expect(canMutateTargetLifecycle({ is_super_admin: true }, undefined)).toBe(false);
  });

  it('gates the activity-log export to platform super admin only', () => {
    expect(canExportActivityLogs({ is_super_admin: true })).toBe(true);
    expect(
      canExportActivityLogs({ is_super_admin: false, is_organization_super_admin: true }),
    ).toBe(false);
    expect(canExportActivityLogs({ is_organization_super_admin: true })).toBe(false);
    expect(canExportActivityLogs(null)).toBe(false);
    expect(canExportActivityLogs({})).toBe(false);
  });
});
