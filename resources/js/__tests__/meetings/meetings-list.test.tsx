import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: {
      id: 7,
      name: 'أحمد',
      access: {
        'meetings.view': true,
        'meetings.create': true,
        'meetings.edit': true,
        'meetings.delete': true,
        'meetings.record_decisions': true,
      },
    },
    can: (p: string) =>
      ['meetings.view', 'meetings.create', 'meetings.edit', 'meetings.delete'].includes(p),
  }),
}));

vi.mock('@features/meetings/api', () => ({
  meetingsApi: {
    getAll: vi.fn().mockResolvedValue({
      data: [{
        id: 1, reference_number: 'MTG-2026-0001', title: 'اجتماع الاختبار',
        description: null, scheduled_at: '2026-06-22T09:00:00Z', duration_minutes: 60,
        location: null, virtual_link: null, agenda: null, minutes: null,
        status: 'scheduled' as const, organizer_id: 7, subject_type: 'project', subject_id: 42,
        organization_id: 1, created_at: '2026-06-19T10:00:00Z', updated_at: '2026-06-19T10:00:00Z',
        status_label: 'مجدول', organizer: { id: 7, name: 'أحمد' },
      }],
      current_page: 1, last_page: 1, per_page: 15, total: 1,
    }),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, p?: Record<string, string>) =>
    p ? k.replace(/\{(\w+)\}/g, (_, n) => p[n]) : k }),
}));

import MeetingsList from '@pages/strategy/meetings/MeetingsList';

describe('MeetingsList', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the meetings table with one row', async () => {
    render(
      <MemoryRouter>
        <MeetingsList />
      </MemoryRouter>
    );
    await waitFor(() =>
      expect(screen.getByText('اجتماع الاختبار')).toBeInTheDocument()
    );
    expect(screen.getByText('MTG-2026-0001')).toBeInTheDocument();
  });

  it('shows a "new meeting" button when user has meetings.create', async () => {
    render(
      <MemoryRouter>
        <MeetingsList />
      </MemoryRouter>
    );
    await waitFor(() =>
      expect(screen.getByText('meetings.meeting.list.new_button')).toBeInTheDocument()
    );
  });
});
