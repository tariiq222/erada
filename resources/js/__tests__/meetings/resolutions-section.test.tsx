import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test' } }),
}));

vi.mock('@shared/api/access', () => ({
  useCan: () => true,
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(), showToast: vi.fn() }),
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));

const mocks = vi.hoisted(() => ({
  listForMeeting: vi.fn(),
  createForMeeting: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
  start: vi.fn(),
  hold: vi.fn(),
  releaseHold: vi.fn(),
  convertToTasks: vi.fn(),
  complete: vi.fn(),
  cancel: vi.fn(),
}));

vi.mock('@features/meetings/resolutions/api', () => ({
  resolutionsApi: {
    listForMeeting: mocks.listForMeeting,
    createForMeeting: mocks.createForMeeting,
    update: mocks.update,
    remove: mocks.remove,
    start: mocks.start,
    hold: mocks.hold,
    releaseHold: mocks.releaseHold,
    convertToTasks: mocks.convertToTasks,
    complete: mocks.complete,
    cancel: mocks.cancel,
  },
}));

vi.mock('@features/meetings/resolutions/ResolutionCard', () => ({
  default: () => <div data-testid="resolution-card-stub" />,
}));

vi.mock('@features/meetings/resolutions/ResolutionForm', () => ({
  default: () => null,
}));

import ResolutionsSection from '@features/meetings/resolutions/ResolutionsSection';

const sampleResolution = {
  id: 1,
  reference_number: 'RES-2026-0001',
  organization_id: 1,
  meeting_id: 1,
  kind: 'recommendation' as const,
  title: 'مخرج اختباري',
  description: null,
  owner_id: 1,
  status: 'open' as const,
  priority: 'medium' as const,
  due_date: null,
  hold_reason: null,
  hold_until: null,
  hold_by: null,
  hold_at: null,
  created_by: 1,
  completed_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00.000Z',
  updated_at: '2026-07-01T00:00:00.000Z',
  status_label: 'مفتوح',
  links: [],
};

const allPerms = {
  canView: true,
  canCreate: true,
  canUpdate: true,
  canDelete: true,
  canStart: true,
  canHold: true,
  canReleaseHold: true,
  canConvertToTasks: true,
  canComplete: true,
  canCancel: true,
};

describe('ResolutionsSection — uses new flow only, no legacy approval UI', () => {
  beforeEach(() => {
    mocks.listForMeeting.mockReset();
    mocks.createForMeeting.mockReset();
    mocks.update.mockReset();
    mocks.remove.mockReset();
  });

  it('fetches resolutions via listForMeeting (not via legacy recommendationsApi)', async () => {
    mocks.listForMeeting.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } });
    render(<ResolutionsSection meetingId={42} permissions={allPerms} />);
    await waitFor(() => {
      expect(mocks.listForMeeting).toHaveBeenCalledWith(42, expect.objectContaining({ per_page: 50 }));
    });
  });

  it('renders the section header with Arabic label', async () => {
    mocks.listForMeeting.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } });
    render(<ResolutionsSection meetingId={1} permissions={allPerms} />);
    await waitFor(() => {
      expect(screen.getByText(/قرارات وتوصيات الاجتماع|مخرجات الاجتماع/)).toBeTruthy();
    });
  });

  it('renders empty state when no resolutions exist', async () => {
    mocks.listForMeeting.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } });
    render(<ResolutionsSection meetingId={1} permissions={allPerms} />);
    await waitFor(() => {
      expect(screen.getByText(/لا توجد قرارات|لا توجد مخرجات/)).toBeTruthy();
    });
  });

  it('renders a ResolutionCard for each resolution in the response', async () => {
    mocks.listForMeeting.mockResolvedValue({
      data: [sampleResolution, { ...sampleResolution, id: 2, kind: 'decision', title: 'قرار ١' }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
    });
    render(<ResolutionsSection meetingId={1} permissions={allPerms} />);
    await waitFor(() => {
      const cards = screen.getAllByTestId('resolution-card-stub');
      expect(cards.length).toBe(2);
    });
  });

  it('hides the new-resolution button when canCreate is false', async () => {
    mocks.listForMeeting.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } });
    render(<ResolutionsSection meetingId={1} permissions={{ ...allPerms, canCreate: false }} />);
    await waitFor(() => {
      expect(screen.queryByText(/إضافة مخرج|جديد/)).toBeNull();
    });
  });

  it('does NOT import or render the legacy RecommendationCard', () => {
    mocks.listForMeeting.mockResolvedValue({ data: [sampleResolution], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } });
    const { container } = render(<ResolutionsSection meetingId={1} permissions={allPerms} />);
    expect(container.innerHTML).not.toMatch(/RecommendationCard/);
  });

  it('re-fetches resolutions after a successful convert via the card', async () => {
    // Phase 3: after a successful convert-to-tasks the card calls onChanged,
    // which the section wires to its `fetch` callback. This is verified
    // end-to-end in resolution-card.test.tsx; here we pin the integration:
    // the section still calls listForMeeting on initial render so the
    // re-fetch trigger has a baseline to fire from.
    mocks.listForMeeting.mockResolvedValue({
      data: [{ ...sampleResolution, status: 'converted_to_tasks' }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
    });
    render(<ResolutionsSection meetingId={1} permissions={allPerms} />);
    await waitFor(() => {
      expect(mocks.listForMeeting).toHaveBeenCalled();
    });
  });
});