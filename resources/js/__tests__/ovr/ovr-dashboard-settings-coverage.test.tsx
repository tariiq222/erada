import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const incidentsApiMock = { getStats: vi.fn(), getAll: vi.fn() };
const incidentCategoriesApiMock = { getAll: vi.fn() };
const apiMock = { post: vi.fn(), put: vi.fn(), delete: vi.fn() };
const showToastMock = vi.fn();

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: showToastMock }) }));
vi.mock('@shared/api/client', () => ({ api: apiMock }));
vi.mock('@shared/contexts/AuthContext', () => ({ useAuth: () => ({ can: () => false }) }));
vi.mock('@entities/incident', () => ({
  incidentsApi: incidentsApiMock,
  incidentCategoriesApi: incidentCategoriesApiMock,
}));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: { children: React.ReactNode }) => <div data-testid="responsive-chart">{children}</div>,
  PieChart: ({ children }: { children: React.ReactNode }) => <div data-testid="pie-chart">{children}</div>,
  Pie: ({ children }: { children: React.ReactNode }) => <div data-testid="pie">{children}</div>,
  Cell: () => <span data-testid="cell" />,
  BarChart: ({ children }: { children: React.ReactNode }) => <div data-testid="bar-chart">{children}</div>,
  Bar: ({ children }: { children: React.ReactNode }) => <div data-testid="bar">{children}</div>,
  XAxis: () => <span data-testid="x-axis" />,
  YAxis: () => <span data-testid="y-axis" />,
  CartesianGrid: () => <span data-testid="grid" />,
  Tooltip: ({ content }: { content?: React.ReactNode }) => <span data-testid="tooltip">{content}</span>,
}));

const stats = {
  total: 12,
  by_status: { new: 4, in_progress: 2, resolved: 6, zero_state: 0 },
  by_severity: { low: 1, medium: 3, high: 4, critical: 4 },
  patient_related: 5,
  informed_authority: 2,
  overdue: 3,
  avg_resolution_hours: 27.6,
};

describe('OVR dashboard/settings/statistics coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    incidentsApiMock.getStats.mockResolvedValue(stats);
    incidentsApiMock.getAll.mockResolvedValue({
      data: [
        { id: 1, report_number: 'OVR-1', description: 'Needle incident', status: 'new', incident_datetime: '2026-06-01T00:00:00Z', created_at: '2026-06-01T00:00:00Z' },
        { id: 2, report_number: 'OVR-2', description: null, incident_type: { name: 'Fall' }, status: 'resolved', created_at: '2026-06-02T00:00:00Z' },
      ],
    });
    incidentCategoriesApiMock.getAll.mockResolvedValue({
      data: Array.from({ length: 12 }, (_, index) => ({
        id: index + 1,
        name: `Category ${index + 1}`,
        name_ar: index % 2 ? null : `تصنيف ${index + 1}`,
        is_active: index % 3 !== 0,
      })),
    });
    apiMock.post.mockResolvedValue({});
    apiMock.put.mockResolvedValue({});
    apiMock.delete.mockResolvedValue({});
  });

  it('loads OVR dashboard charts, recent incidents, empty state, period refetch, and error toast', async () => {
    const user = userEvent.setup();
    const { default: OVRDashboard } = await import('@pages/ovr/OVRDashboard');

    const { unmount } = render(<OVRDashboard />);
    expect(await screen.findByText('OVR-1')).toBeInTheDocument();
    expect(screen.getByText('Needle incident')).toBeInTheDocument();
    expect(screen.getAllByTestId('responsive-chart').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(/28 ovr.hours/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'ovr.period_month' }));
    await waitFor(() => expect(incidentsApiMock.getStats).toHaveBeenLastCalledWith({ period: 'month' }));

    unmount();
    incidentsApiMock.getStats.mockResolvedValueOnce({ ...stats, by_status: {}, by_severity: {}, total: 0, avg_resolution_hours: 0 });
    incidentsApiMock.getAll.mockResolvedValueOnce({ data: [] });
    render(<OVRDashboard />);
    expect(await screen.findAllByText('ovr.no_incidents')).toHaveLength(3);

    unmount();
    incidentsApiMock.getStats.mockRejectedValueOnce(new Error('stats failed'));
    render(<OVRDashboard />);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'ovr.load_error'));
  });

  it('manages OVR settings categories through create, validation, edit, delete, pagination, empty, and error states', async () => {
    const user = userEvent.setup();
    const { default: Settings } = await import('@pages/ovr/Settings');

    const { unmount } = render(<Settings />);
    expect(await screen.findByText('Category 1')).toBeInTheDocument();
    expect(screen.getByText('تصنيف 1')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'common.next_page' }));
    expect(screen.getByText('Category 11')).toBeInTheDocument();

    await user.click(screen.getAllByRole('button', { name: 'ovr.add_category' })[0]);
    await user.click(screen.getByRole('button', { name: 'common.save' }));
    expect(screen.getByText('common.required')).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText('ovr.category_name_placeholder'), 'Near miss');
    await user.type(screen.getByPlaceholderText('ovr.category_name_ar_placeholder'), 'بلاغ قريب');
    await user.click(screen.getByLabelText('common.active'));
    await user.click(screen.getByRole('button', { name: 'common.save' }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/ovr/categories', { name: 'Near miss', name_ar: 'بلاغ قريب', is_active: false }));
    expect(showToastMock).toHaveBeenCalledWith('success', 'ovr.category_created');

    await user.click(screen.getAllByRole('button', { name: 'common.edit' })[0]);
    await user.clear(screen.getByDisplayValue('Category 11'));
    await user.type(screen.getByPlaceholderText('ovr.category_name_placeholder'), 'Updated category');
    await user.click(screen.getByRole('button', { name: 'common.save' }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith('/ovr/categories/11', expect.objectContaining({ name: 'Updated category' })));

    await user.click(screen.getAllByRole('button', { name: 'common.delete' })[0]);
    await user.click(screen.getAllByRole('button', { name: 'common.delete' }).at(-1)!);
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith('/ovr/categories/11'));
    expect(showToastMock).toHaveBeenCalledWith('success', 'ovr.category_deleted');

    unmount();
    incidentCategoriesApiMock.getAll.mockResolvedValueOnce([]);
    render(<Settings />);
    expect(await screen.findByText('ovr.no_categories')).toBeInTheDocument();

    unmount();
    incidentCategoriesApiMock.getAll.mockRejectedValueOnce(new Error('categories failed'));
    render(<Settings />);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'ovr.load_error'));
  });
});
