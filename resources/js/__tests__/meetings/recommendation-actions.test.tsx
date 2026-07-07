import { describe, it, expect, vi } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import RecommendationStatusActions from '@pages/strategy/meetings/recommendations/view/RecommendationStatusActions';

/**
 * Frontend ↔ backend parity for the Recommendations lifecycle.
 *
 * Direction B (2026-07-06) replaced the single `meetings.record_decisions`
 * capability with the engine-enforced `recommendations.{approve, reject,
 * defer, accept, complete}` set. This test pins the per-action gating in
 * `RecommendationStatusActions`: when the parent passes only
 * `canApprove=true` and the rest `false`, only the Accept/Approve button
 * is enabled — Reject/Defer/Complete must stay disabled with the
 * localized "missing capability" tooltip.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
  }),
}));

const mockRecommendation = {
  id: 11,
  reference_number: 'REC-2026-0011',
  kind: 'action_item' as const,
  title: 'إعادة جدولة المرحلة 2',
  description: null,
  status: 'proposed' as const,
  organization_id: 1,
  meeting_id: null,
  decidable_type: null,
  decidable_id: null,
  type: null,
  rationale: null,
  impact: null,
  requested_by: null,
  made_by: null,
  decision_date: null,
  effective_date: null,
  assignee_id: 7,
  due_date: '2026-07-15',
  priority: 'medium' as const,
  completed_at: null,
  defer_reason: null,
  deferred_until: null,
  deferred_by: null,
  deferred_at: null,
  has_pending_tasks: false,
  created_at: '2026-06-19T10:00:00Z',
  updated_at: '2026-06-19T10:00:00Z',
  status_label: 'مقترح',
  priority_label: 'متوسطة',
  is_overdue: false,
};

describe('RecommendationStatusActions — per-action capability gating', () => {
  it('enables only the Accept button when only canAccept=true', () => {
    render(
      <MemoryRouter>
        <RecommendationStatusActions
          recommendation={mockRecommendation}
          canAccept={true}
          canReject={false}
          canDefer={false}
          canComplete={false}
          onAccept={vi.fn()}
          onReject={vi.fn()}
          onDefer={vi.fn()}
          onComplete={vi.fn()}
        />
      </MemoryRouter>,
    );

    // All four action buttons render; only Accept is enabled.
    const acceptBtn = screen.getByRole('button', { name: 'meetings.recommendation.actions.accept' });
    const rejectBtn = screen.getByRole('button', { name: 'meetings.recommendation.actions.reject' });
    const deferBtn = screen.getByRole('button', { name: 'meetings.recommendation.actions.defer' });
    const completeBtn = screen.getByRole('button', { name: 'meetings.recommendation.actions.complete' });

    expect(acceptBtn).not.toBeDisabled();
    expect(rejectBtn).toBeDisabled();
    expect(deferBtn).toBeDisabled();
    expect(completeBtn).toBeDisabled();
  });

  it('disables every action when no capabilities are granted', () => {
    render(
      <MemoryRouter>
        <RecommendationStatusActions
          recommendation={mockRecommendation}
          canAccept={false}
          canReject={false}
          canDefer={false}
          canComplete={false}
          onAccept={vi.fn()}
          onReject={vi.fn()}
          onDefer={vi.fn()}
          onComplete={vi.fn()}
        />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.accept' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.reject' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.defer' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.complete' })).toBeDisabled();
  });

  it('keeps a button disabled when the status forbids the transition even if capability is granted', () => {
    // Recommendation status = 'proposed', so Complete (which requires
    // status='accepted') must stay disabled even with canComplete=true.
    render(
      <MemoryRouter>
        <RecommendationStatusActions
          recommendation={mockRecommendation}
          canAccept={true}
          canReject={true}
          canDefer={true}
          canComplete={true}
          onAccept={vi.fn()}
          onReject={vi.fn()}
          onDefer={vi.fn()}
          onComplete={vi.fn()}
        />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.accept' })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.reject' })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.defer' })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: 'meetings.recommendation.actions.complete' })).toBeDisabled();
  });
});