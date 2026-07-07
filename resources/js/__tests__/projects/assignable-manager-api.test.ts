/**
 * TDD contract for `projectsApi.getAssignableManagers(type)` — the frontend
 * counterpart to `GET /api/projects/assignable-managers?type=development|improvement`.
 *
 * Mocks the shared API client (same pattern used by the rest of the project
 * API tests). Verifies the URL, query string, and that the unwrapped data
 * array is returned to callers.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockApi = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
};

vi.mock('@shared/api/client', () => ({ api: mockApi }));

describe('projectsApi.getAssignableManagers', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockApi.get.mockResolvedValue({ data: [] });
    mockApi.post.mockResolvedValue({});
    mockApi.put.mockResolvedValue({});
    mockApi.delete.mockResolvedValue({});
  });

  it('GETs /projects/assignable-managers?type=development and returns the data array', async () => {
    const { projectsApi } = await import('@entities/project');
    const sample = [
      { id: 10, name: 'Sara', email: 'sara@example.test', job_title: 'Project Manager', department_id: 1 },
      { id: 11, name: 'Omar', email: 'omar@example.test', job_title: null, department_id: null },
    ];
    mockApi.get.mockResolvedValueOnce({ data: sample });

    const result = await projectsApi.getAssignableManagers('development');

    expect(mockApi.get).toHaveBeenCalledTimes(1);
    expect(mockApi.get).toHaveBeenCalledWith('/projects/assignable-managers?type=development');
    expect(result).toBe(sample);
    expect(result).toHaveLength(2);
    expect(result[0]).toMatchObject({
      id: 10,
      name: 'Sara',
      email: 'sara@example.test',
      job_title: 'Project Manager',
      department_id: 1,
    });
  });

  it('also accepts type=improvement and forwards it in the query string', async () => {
    const { projectsApi } = await import('@entities/project');
    mockApi.get.mockResolvedValueOnce({ data: [] });

    const result = await projectsApi.getAssignableManagers('improvement');

    expect(mockApi.get).toHaveBeenCalledWith('/projects/assignable-managers?type=improvement');
    expect(result).toEqual([]);
  });

  it('returns an empty array when the backend responds with empty data', async () => {
    const { projectsApi } = await import('@entities/project');
    mockApi.get.mockResolvedValueOnce({ data: [] });

    const result = await projectsApi.getAssignableManagers('development');

    expect(result).toEqual([]);
  });
});