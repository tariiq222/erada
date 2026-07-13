import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiMock = vi.hoisted(() => ({
  get: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
    current_page: 1,
    last_page: 1,
    per_page: 100,
    total: 0,
  }),
  post: vi.fn().mockResolvedValue({ data: { id: 1 } }),
  put: vi.fn().mockResolvedValue({ data: { id: 1 } }),
  patch: vi.fn().mockResolvedValue({ data: { id: 1 } }),
  delete: vi.fn().mockResolvedValue({ data: { id: 1 } }),
  blob: vi.fn().mockResolvedValue(new Blob()),
}));

vi.mock('@shared/api/client', () => ({ api: apiMock }));

import { adminApi } from '@admin/api/adminApi';

type ApiMethod = keyof typeof apiMock;
type ContractCase = {
  name: string;
  method: ApiMethod;
  call: () => Promise<unknown>;
  expectedArgs: unknown[];
};

const organizationInput = { name: 'Organization' };
const roleInput = { label_en: 'Role' };
const userInput = { name: 'User' };
const departmentInput = { name: 'Department' };
const governanceInput = {
  resource_type: 'project',
  governing_unit_id: 7,
};
const incidentTypeInput = {
  name: 'Incident',
  name_ar: 'حادث',
  is_active: true,
  requires_reportable_type: false,
};

describe('adminApi canonical route contract', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const canonicalCases: ContractCase[] = [
    {
      name: 'organizations.list',
      method: 'get',
      call: () => adminApi.organizations.list({ page: 2, search: 'alpha' }),
      expectedArgs: ['/organizations?page=2&search=alpha'],
    },
    {
      name: 'organizations.get',
      method: 'get',
      call: () => adminApi.organizations.get(7),
      expectedArgs: ['/organizations/7'],
    },
    {
      name: 'organizations.create',
      method: 'post',
      call: () => adminApi.organizations.create(organizationInput),
      expectedArgs: ['/organizations', organizationInput],
    },
    {
      name: 'organizations.update',
      method: 'put',
      call: () => adminApi.organizations.update(7, organizationInput),
      expectedArgs: ['/organizations/7', organizationInput],
    },
    {
      name: 'organizations.delete',
      method: 'delete',
      call: () => adminApi.organizations.delete(7),
      expectedArgs: ['/organizations/7'],
    },
    {
      name: 'organizations.all',
      method: 'get',
      call: () => adminApi.organizations.all(),
      expectedArgs: ['/organizations?per_page=100&page=1'],
    },
    {
      name: 'users.summary',
      method: 'get',
      call: () => adminApi.users.summary(),
      expectedArgs: ['/users?per_page=100'],
    },
    {
      name: 'users.list',
      method: 'get',
      call: () => adminApi.users.list({ organization_id: 7, page: 2 }),
      expectedArgs: ['/users?organization_id=7&page=2'],
    },
    {
      name: 'users.get',
      method: 'get',
      call: () => adminApi.users.get(9),
      expectedArgs: ['/users/9'],
    },
    {
      name: 'users.security',
      method: 'get',
      call: () => adminApi.users.security(9),
      expectedArgs: ['/users/9/security'],
    },
    {
      name: 'users.create',
      method: 'post',
      call: () => adminApi.users.create(userInput),
      expectedArgs: ['/users', userInput],
    },
    {
      name: 'users.update',
      method: 'put',
      call: () => adminApi.users.update(9, userInput),
      expectedArgs: ['/users/9', userInput],
    },
    {
      name: 'users.unlock',
      method: 'post',
      call: () => adminApi.users.unlock(9),
      expectedArgs: ['/users/9/unlock', undefined],
    },
    {
      name: 'users.delete',
      method: 'delete',
      call: () => adminApi.users.delete(9),
      expectedArgs: ['/users/9'],
    },
    {
      name: 'users.all',
      method: 'get',
      call: () => adminApi.users.all(7),
      expectedArgs: ['/users?organization_id=7&per_page=100&page=1'],
    },
    {
      name: 'roles.list',
      method: 'get',
      call: () => adminApi.roles.list(),
      expectedArgs: ['/roles'],
    },
    {
      name: 'roles.get',
      method: 'get',
      call: () => adminApi.roles.get(5),
      expectedArgs: ['/roles/5'],
    },
    {
      name: 'roles.abilities',
      method: 'get',
      call: () => adminApi.roles.abilities(),
      expectedArgs: ['/roles/abilities'],
    },
    {
      name: 'roles.scopeOptions',
      method: 'get',
      call: () => adminApi.roles.scopeOptions(),
      expectedArgs: ['/roles/scope-options'],
    },
    {
      name: 'roles.create',
      method: 'post',
      call: () => adminApi.roles.create(roleInput),
      expectedArgs: ['/roles', roleInput],
    },
    {
      name: 'roles.update',
      method: 'put',
      call: () => adminApi.roles.update(5, roleInput),
      expectedArgs: ['/roles/5', roleInput],
    },
    {
      name: 'roles.delete',
      method: 'delete',
      call: () => adminApi.roles.delete(5),
      expectedArgs: ['/roles/5'],
    },
    {
      name: 'governance.list',
      method: 'get',
      call: () => adminApi.governance.list(),
      expectedArgs: ['/governance-rules'],
    },
    {
      name: 'governance.update',
      method: 'put',
      call: () => adminApi.governance.update(governanceInput),
      expectedArgs: ['/governance-rules', governanceInput],
    },
    {
      name: 'scopeTypes.list',
      method: 'get',
      call: () => adminApi.scopeTypes.list({ page: 2 }),
      expectedArgs: ['/scope-types?page=2'],
    },
    {
      name: 'activityLogs.list',
      method: 'get',
      call: () => adminApi.activityLogs.list({ organization_id: 7, page: 2 }),
      expectedArgs: ['/activity-logs?organization_id=7&page=2'],
    },
    {
      name: 'activityLogs.export',
      method: 'blob',
      call: () => adminApi.activityLogs.export('csv', { organization_id: 7, action: 'updated' }),
      expectedArgs: ['/activity-logs/export?format=csv&organization_id=7&action=updated'],
    },
    {
      name: 'scopedRoleAudit.list',
      method: 'get',
      call: () => adminApi.scopedRoleAudit.list({ page: 2 }),
      expectedArgs: ['/authorization-role-assignments/audit-logs?page=2'],
    },
    {
      name: 'access.summary',
      method: 'get',
      call: () => adminApi.access.summary(9),
      expectedArgs: ['/authorization-role-assignments/user/9/access-summary'],
    },
  ];

  for (const contract of canonicalCases) {
    it(`${contract.name} calls the exact canonical endpoint`, async () => {
      await contract.call();

      expect(apiMock[contract.method]).toHaveBeenCalledTimes(1);
      expect(apiMock[contract.method]).toHaveBeenCalledWith(...contract.expectedArgs);
    });
  }

  const preservedAdminCases: ContractCase[] = [
    {
      name: 'overview',
      method: 'get',
      call: () => adminApi.overview(),
      expectedArgs: ['/admin/overview'],
    },
    {
      name: 'securityAlerts',
      method: 'get',
      call: () => adminApi.securityAlerts(),
      expectedArgs: ['/admin/security/alerts'],
    },
    {
      name: 'auditRecent',
      method: 'get',
      call: () => adminApi.auditRecent({ page: 2, per_page: 25 }),
      expectedArgs: ['/admin/audit/recent?page=2&per_page=25'],
    },
    {
      name: 'departments.list',
      method: 'get',
      call: () => adminApi.departments.list({ organization_id: 7, page: 2 }),
      expectedArgs: ['/admin/departments?organization_id=7&page=2'],
    },
    {
      name: 'departments.get',
      method: 'get',
      call: () => adminApi.departments.get(3),
      expectedArgs: ['/admin/departments/3'],
    },
    {
      name: 'departments.hierarchy',
      method: 'get',
      call: () => adminApi.departments.hierarchy(),
      expectedArgs: ['/admin/departments/hierarchy'],
    },
    {
      name: 'departments.create',
      method: 'post',
      call: () => adminApi.departments.create(departmentInput),
      expectedArgs: ['/admin/departments', departmentInput],
    },
    {
      name: 'departments.update',
      method: 'put',
      call: () => adminApi.departments.update(3, departmentInput),
      expectedArgs: ['/admin/departments/3', departmentInput],
    },
    {
      name: 'departments.delete',
      method: 'delete',
      call: () => adminApi.departments.delete(3),
      expectedArgs: ['/admin/departments/3'],
    },
    {
      name: 'departments.summary',
      method: 'get',
      call: () => adminApi.departments.summary(7),
      expectedArgs: ['/admin/departments?organization_id=7&per_page=100&page=1'],
    },
    {
      name: 'incidentTypes.list',
      method: 'get',
      call: () => adminApi.incidentTypes.list({ include_inactive: true }),
      expectedArgs: ['/admin/incident-types?include_inactive=1'],
    },
    {
      name: 'incidentTypes.create',
      method: 'post',
      call: () => adminApi.incidentTypes.create(incidentTypeInput),
      expectedArgs: ['/admin/incident-types', incidentTypeInput],
    },
    {
      name: 'incidentTypes.update',
      method: 'put',
      call: () => adminApi.incidentTypes.update('type-1', incidentTypeInput),
      expectedArgs: ['/admin/incident-types/type-1', incidentTypeInput],
    },
    {
      name: 'incidentTypes.delete',
      method: 'delete',
      call: () => adminApi.incidentTypes.delete('type-1'),
      expectedArgs: ['/admin/incident-types/type-1'],
    },
    {
      name: 'incidentTypes.addReportableType',
      method: 'post',
      call: () => adminApi.incidentTypes.addReportableType('type-1', { name: 'Reportable', name_ar: 'قابل للإبلاغ' }),
      expectedArgs: ['/admin/incident-types/type-1/reportable-types', { name: 'Reportable', name_ar: 'قابل للإبلاغ' }],
    },
  ];

  for (const contract of preservedAdminCases) {
    it(`${contract.name} preserves the exact registered admin endpoint`, async () => {
      await contract.call();

      expect(apiMock[contract.method]).toHaveBeenCalledTimes(1);
      expect(apiMock[contract.method]).toHaveBeenCalledWith(...contract.expectedArgs);
    });
  }
});
