import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockApi = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
};

vi.mock('@shared/api/client', () => ({ api: mockApi }));

describe('canonical role management API', () => {
  beforeEach(() => vi.clearAllMocks());

  it('uses numeric role ids and the canonical capability contract', async () => {
    const write = {
      name: 'portfolio_reviewer',
      label: 'مراجع المحافظ',
      label_ar: 'مراجع المحافظ',
      label_en: 'Portfolio reviewer',
      scope_type: 'organization',
      capabilities: ['portfolios.view', 'projects.view'],
      reach: { portfolios: 'all', projects: 'department' },
      is_active: true,
    };

    const { rolesApi } = await import('@entities/role/api/role.api');
    await rolesApi.get(27);
    await rolesApi.create(write);
    await rolesApi.update(27, write);
    await rolesApi.delete(27);

    expect(mockApi.get).toHaveBeenCalledWith('/roles/27');
    expect(mockApi.post).toHaveBeenCalledWith('/roles', write);
    expect(mockApi.put).toHaveBeenCalledWith('/roles/27', write);
    expect(mockApi.delete).toHaveBeenCalledWith('/roles/27');
    expect(write).not.toHaveProperty('guard_name');
    expect(write).not.toHaveProperty('permissions');
    expect(write).not.toHaveProperty('permissions_capabilities');
  });

  it('loads only the canonical capability and scope registries', async () => {
    const { rolesApi } = await import('@entities/role/api/role.api');

    await rolesApi.abilities();
    await rolesApi.scopeOptions();

    expect(mockApi.get).toHaveBeenNthCalledWith(1, '/roles/abilities');
    expect(mockApi.get).toHaveBeenNthCalledWith(2, '/roles/scope-options');
  });
});
