/* eslint-disable @typescript-eslint/no-explicit-any */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockApi = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
  blob: vi.fn(),
};

vi.mock('@shared/api/client', () => ({ api: mockApi }));

describe('entity API endpoint contracts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockApi.get.mockResolvedValue({});
    mockApi.post.mockResolvedValue({});
    mockApi.put.mockResolvedValue({});
    mockApi.delete.mockResolvedValue({});
    mockApi.blob.mockResolvedValue(new Blob());
  });

  it('covers survey, data import, and public survey endpoints with query strings', async () => {
    const { surveysApi, dataImportsApi, publicSurveysApi } = await import('@entities/survey/api/survey.api');

    await surveysApi.getAll({ status: 'published', search: 'رضا' });
    await surveysApi.getStats();
    await surveysApi.getById(7);
    await surveysApi.create({ title: 'S', description: 'D' } as any);
    await surveysApi.update(7, { title: 'Updated' } as any);
    await surveysApi.delete(7);
    await surveysApi.publish(7);
    await surveysApi.close(7, 'done');
    await surveysApi.createNewRevision(7);
    await surveysApi.getRevisions(7);
    await surveysApi.getAnalytics(7);
    await surveysApi.getFields(7);
    await surveysApi.addField(7, { label: 'Name' } as any);
    await surveysApi.updateField(7, 3, { label: 'Age' } as any);
    await surveysApi.deleteField(7, 3);
    await surveysApi.reorderFields(7, [3, 4]);
    await surveysApi.getSections(7);
    await surveysApi.addSection(7, { title: 'Section' });
    await surveysApi.updateSection(7, 2, { description: 'Updated' });
    await surveysApi.deleteSection(7, 2);
    await surveysApi.reorderSections(7, [2, 1]);
    await surveysApi.getResponses(7, { page: '2' });
    await surveysApi.getResponse(7, 9);
    await surveysApi.flagResponse(7, 9, 'review');
    await surveysApi.reviewResponse(7, 9, { status: 'approved' });
    await surveysApi.exportResponses(7);
    await surveysApi.getInvitations(7);
    await surveysApi.createInvitation(7, { email: 'a@example.test' });
    await surveysApi.bulkCreateInvitations(7, { invitations: [{ email: 'b@example.test' }] });
    await surveysApi.revokeInvitation(7, 4);
    await surveysApi.resendInvitation(7, 4);
    await surveysApi.getMappings(7);
    await surveysApi.createMapping(7, { target: 'projects' });
    await surveysApi.updateMapping(7, 5, { target: 'tasks' });
    await surveysApi.deleteMapping(7, 5);
    await surveysApi.getAvailableTargets();
    await dataImportsApi.getAll({ status: 'pending' });
    await dataImportsApi.getById(10);
    await dataImportsApi.approve(10);
    await dataImportsApi.reject(10, 'bad');
    await dataImportsApi.apply(10);
    await dataImportsApi.bulkApprove([10, 11]);
    await dataImportsApi.bulkReject([12], 'duplicate');
    await publicSurveysApi.getByCode('abc', 2);
    await publicSurveysApi.getByCode('abc');
    await publicSurveysApi.submit('abc', { answers: {}, version_hash: 'v1' });
    await publicSurveysApi.getByInvitation('token');
    await publicSurveysApi.submitByInvitation('token', { answers: {}, version_hash: 'v1' });

    expect(mockApi.get).toHaveBeenCalledWith('/surveys?status=published&search=%D8%B1%D8%B6%D8%A7');
    expect(mockApi.post).toHaveBeenCalledWith('/surveys/7/fields/reorder', { fields: [3, 4] });
    expect(mockApi.post).toHaveBeenCalledWith('/data-imports/bulk-reject', { ids: [12], reason: 'duplicate' });
    expect(mockApi.get).toHaveBeenCalledWith('/surveys/public/abc?rev=2');
    expect(mockApi.get).toHaveBeenCalledWith('/surveys/public/abc');
  });

  it('covers risk management API endpoints and export URLs', async () => {
    const { risksApi, risksDashboardApi } = await import('@entities/risk/api/risk.api');

    await risksApi.list({ status: 'open', page: 2 });
    await risksApi.get(5);
    await risksApi.create({ title: 'Risk' });
    await risksApi.update(5, { title: 'Updated' });
    await risksApi.remove(5);
    await risksApi.reassess(5, { likelihood: 4 });
    await risksApi.changeStatus(5, { to_status: 'closed', reason: 'done' });
    await risksApi.statusHistory(5);
    await risksApi.addAction(5, { title: 'Action' });
    await risksApi.getAction(8);
    await risksApi.updateAction(8, { progress: 50 });
    await risksApi.removeAction(8);
    await risksApi.addActionUpdate(8, { comment: 'done' });
    await risksApi.listActionUpdates(8);
    await risksDashboardApi.get();
    await risksDashboardApi.getMatrix();

    expect(mockApi.get).toHaveBeenCalledWith('/risk-management/risks?status=open&page=2');
    expect(mockApi.post).toHaveBeenCalledWith('/risk-management/risks/5/status-changes', { to_status: 'closed', reason: 'done' });
    expect(risksDashboardApi.exportUrl()).toBe('/api/risk-management/export/csv');
    expect(risksDashboardApi.exportUrl('pdf')).toBe('/api/risk-management/export/pdf');
  });

  it('covers admin, role, and authorization-assignment API wrappers including export failure behavior', async () => {
    const { organizationsApi, scopeTypesApi, activityLogsApi } = await import('@entities/admin/api/admin.api');
    const { rolesApi } = await import('@entities/role/api/role.api');
    const { authorizationAssignmentsApi } = await import('@entities/authorization-assignment/api/authorization-assignment.api');

    await organizationsApi.list({ active: true, page: 1 });
    await organizationsApi.get(1);
    await organizationsApi.create({ name: 'Org' } as any);
    await organizationsApi.update(1, { name: 'Updated' } as any);
    await organizationsApi.delete(1);
    await scopeTypesApi.list();
    await activityLogsApi.list({ action: 'login' });
    await activityLogsApi.get(3);
    await rolesApi.list();
    await rolesApi.get(4);
    const roleWrite = {
      name: 'reviewer',
      label: 'Reviewer',
      scope_type: 'organization',
      capabilities: ['projects.view'],
      reach: { projects: 'department' },
      is_active: true,
    };
    await rolesApi.create(roleWrite);
    await rolesApi.update(4, { ...roleWrite, capabilities: ['projects.edit'] });
    await rolesApi.delete(4);
    await rolesApi.abilities();
    await rolesApi.scopeOptions();
    await rolesApi.assignToUser({ user_id: 9, replace_all: true, assignments: [] });
    await authorizationAssignmentsApi.auditLogs({ user_id: 9, role: 'manager', scope_type: '', page: 1 } as any);

    const blob = new Blob(['csv']);
    mockApi.blob.mockResolvedValueOnce(blob);
    await expect(activityLogsApi.exportCsv({ action: 'login' })).resolves.toBe(blob);
    expect(mockApi.blob).toHaveBeenCalledWith('/activity-logs/export?format=csv&action=login');

    mockApi.blob.mockRejectedValueOnce(new Error('Export failed'));
    await expect(activityLogsApi.exportJson({ action: 'logout' })).rejects.toThrow('Export failed');

    expect(mockApi.get).toHaveBeenCalledWith('/organizations?active=true&page=1');
    expect(mockApi.get).toHaveBeenCalledWith('/authorization-role-assignments/audit-logs?user_id=9&role=manager&page=1');
  });
});
