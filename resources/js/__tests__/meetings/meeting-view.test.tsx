import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => i18nKey,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}));
import { MemoryRouter, Route, Routes } from 'react-router-dom';

vi.mock('@features/meetings/api', () => ({
  meetingsApi: {
    getOne: vi.fn(),
    delete: vi.fn(),
    start: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
    updateMinutes: vi.fn(),
  },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test User' }, hasPermission: () => true, canAccess: () => true, isAdmin: false }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

const mockMeeting = {
  id: 1,
  reference_number: 'MTG-001',
  title: 'Test Meeting',
  description: null,
  scheduled_at: '2026-07-01T10:00:00.000Z',
  duration_minutes: 60,
  location: null,
  virtual_link: null,
  agenda: null,
  minutes: null,
  status: 'scheduled' as const,
  organizer_id: 1,
  subject_type: null,
  subject_id: null,
  organization_id: 1,
  created_at: '2026-06-01T00:00:00.000Z',
  updated_at: '2026-06-01T00:00:00.000Z',
  status_label: 'Scheduled',
  organizer: { id: 1, name: 'Test User' },
  attendees: [],
};

describe('MeetingView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders meeting title after loading', async () => {
    const { meetingsApi } = await import('@features/meetings/api');
    vi.mocked(meetingsApi.getOne).mockResolvedValue(mockMeeting as unknown as never);

    const MeetingView = (await import('@pages/strategy/meetings/MeetingView')).default;

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/1']}>
        <Routes>
          <Route path="/strategy/meetings/:id" element={<MeetingView />} />
          <Route path="/strategy/meetings" element={<div>List</div>} />
        </Routes>
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByText('Test Meeting')).toBeInTheDocument();
    });
  });

  it('shows skeleton while loading', async () => {
    const { meetingsApi } = await import('@features/meetings/api');
    vi.mocked(meetingsApi.getOne).mockReturnValue(new Promise(() => {}));

    const MeetingView = (await import('@pages/strategy/meetings/MeetingView')).default;

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/1']}>
        <Routes>
          <Route path="/strategy/meetings/:id" element={<MeetingView />} />
        </Routes>
      </MemoryRouter>
    );

    // Loading state — skeleton should show, not the meeting title
    expect(screen.queryByText('Test Meeting')).not.toBeInTheDocument();
  });
});
