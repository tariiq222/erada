import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
}));

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
}));

const sampleBase = {
  id: 1,
  reference_number: 'RES-2026-0001',
  organization_id: 1,
  meeting_id: 1,
  kind: 'decision',
  title: 'مخرج للاختبار',
  description: null,
  owner_id: 1,
  status: 'open',
  priority: 'medium',
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
  owner: { id: 1, name: 'مسؤول' },
  meeting: { id: 1, title: 'اجتماع ١', reference_number: 'MTG-001' },
  links: [],
};

vi.mock('@shared/api/client', () => ({
  api: {
    get: mocks.get,
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

import { ResolutionsPage } from '@pages/strategy/meetings/resolutions';

describe('ResolutionsPage filters', () => {
  it('renders the page header with the canonical Arabic title', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [
        {
          id: 1,
          reference_number: 'RES-2026-0001',
          organization_id: 1,
          meeting_id: 1,
          kind: 'recommendation',
          title: 'مخرج للاختبار',
          description: null,
          owner_id: 1,
          status: 'open',
          priority: 'medium',
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
          owner: { id: 1, name: 'مسؤول ١' },
          meeting: { id: 1, title: 'اجتماع ١', reference_number: 'MTG-001' },
          links: [],
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });

    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByText(/متابعة مخرجات الاجتماعات|مخرجات الاجتماعات/)).toBeTruthy();
    });
  });

  it('fetches /meeting-resolutions on mount', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(mocks.get).toHaveBeenCalledWith(expect.stringContaining('/meeting-resolutions'));
    });
  });

  it('does not call the legacy /recommendations or /strategy/decisions endpoints', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(mocks.get).toHaveBeenCalled();
    });
    const allCalls = mocks.get.mock.calls.map((c) => c[0] ?? '').join(',');
    expect(allCalls).not.toMatch(/\/recommendations(\?|$)/);
    expect(allCalls).not.toMatch(/\/strategy\/decisions/);
  });
});

describe('ResolutionsPage — Phase 4 progress + hold display', () => {
  it('renders the tasks_progress column for resolutions with tasks', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [{
        ...sampleBase,
        id: 1,
        title: 'مخرج بمهام',
        status: 'converted_to_tasks',
        tasks_count: 4,
        completed_tasks_count: 1,
        pending_tasks_count: 3,
        completion_percentage: 25,
      }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByText(/1 \/ 4/)).toBeTruthy();
      expect(screen.getByText(/25%/)).toBeTruthy();
    });
  });

  it('renders a dash for resolutions with zero tasks', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [{ ...sampleBase, id: 1, tasks_count: 0, completed_tasks_count: 0, pending_tasks_count: 0, completion_percentage: 0 }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      // The dashes (—) for tasks progress should be visible.
      const dashes = screen.getAllByText('—');
      expect(dashes.length).toBeGreaterThan(0);
    });
  });

  it('shows a hold indicator badge when is_on_hold is true', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [{
        ...sampleBase,
        id: 1,
        status: 'open',
        is_on_hold: true,
      }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByTestId('hold-indicator')).toBeTruthy();
      expect(screen.getByText(/معلّق/)).toBeTruthy();
    });
  });

  it('does NOT show hold indicator when is_on_hold is false', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [{ ...sampleBase, id: 1, is_on_hold: false }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.queryByTestId('hold-indicator')).toBeNull();
    });
  });

  it('does not render approve/reject/adopt anywhere on the page', async () => {
    mocks.get.mockResolvedValueOnce({
      data: [{ ...sampleBase, id: 1 }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });
    render(<MemoryRouter><ResolutionsPage /></MemoryRouter>);
    await waitFor(() => {
      const html = document.body.innerHTML;
      expect(html).not.toMatch(/approve|اعتماد/i);
      expect(html).not.toMatch(/reject|رفض/i);
      expect(html).not.toMatch(/adopt/i);
      expect(html).not.toMatch(/deliberate/i);
    });
  });
});