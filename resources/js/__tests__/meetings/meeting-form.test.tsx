import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

// ---------------------------------------------------------------------------
// Mock react-router-dom — preserve everything, only stub useNavigate
// ---------------------------------------------------------------------------
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => vi.fn() };
});

// ---------------------------------------------------------------------------
// Mock meetings API — all methods needed by MeetingForm + MeetingView
// ---------------------------------------------------------------------------
vi.mock('@features/meetings/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@features/meetings/api')>()),
  meetingsApi: {
    getOne: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    start: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
    updateMinutes: vi.fn(),
    getAll: vi.fn(),
  },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Test User', email: 'test@test.com' },
    hasPermission: () => true,
    canAccess: () => true,
    isAdmin: false,
  }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

vi.mock('@entities/project', () => ({
  projectsApi: { getAll: vi.fn().mockResolvedValue({ data: [] }) },
}));

vi.mock('@entities/strategy', () => ({
  portfoliosApi: { getList: vi.fn().mockResolvedValue({ data: [] }) },
  programsApi: { getList: vi.fn().mockResolvedValue({ data: [] }) },
}));

vi.mock('@entities/risk', () => ({
  risksApi: { list: vi.fn().mockResolvedValue({ data: [] }) },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

// ---------------------------------------------------------------------------
// Shared meeting fixture
// ---------------------------------------------------------------------------
const mockMeeting = {
  id: 1,
  reference_number: 'MTG-001',
  title: 'Edit Me',
  description: 'Some description',
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

describe('MeetingForm', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // -------------------------------------------------------------------------
  // Test 1 — create mode: fill title, submit, assert create API called
  // -------------------------------------------------------------------------
  it('create mode: submits form and calls meetingsApi.create', async () => {
    const { meetingsApi } = await import('@features/meetings/api');
    vi.mocked(meetingsApi.create).mockResolvedValue({ ...mockMeeting, id: 99, title: 'New Meeting' } as unknown as never);

    const MeetingForm = (await import('@pages/strategy/meetings/MeetingForm')).default;
    const user = userEvent.setup();

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/create']}>
        <MeetingForm mode="page" />
      </MemoryRouter>
    );

    // Wait for the form to be visible (not in loading state).
    // The label includes a required asterisk span, so we match with exact: false.
    await waitFor(() => {
      expect(screen.getByLabelText('meetings.meeting.fields.title', { exact: false })).toBeInTheDocument();
    });

    // Fill in the title field
    const titleInput = screen.getByLabelText('meetings.meeting.fields.title', { exact: false });
    await user.clear(titleInput);
    await user.type(titleInput, 'New Meeting');

    // Submit via the create button
    const submitBtn = screen.getByRole('button', { name: 'meetings.meeting.form.submit_create' });
    await user.click(submitBtn);

    // Assert create was called (update must NOT have been called)
    await waitFor(() => {
      expect(vi.mocked(meetingsApi.create)).toHaveBeenCalled();
    });
    expect(vi.mocked(meetingsApi.update)).not.toHaveBeenCalled();

    // Verify the title was included in the payload
    const callArg = vi.mocked(meetingsApi.create).mock.calls[0][0] as { title: string };
    expect(callArg.title).toBe('New Meeting');
  });

  // -------------------------------------------------------------------------
  // Test 2 — edit mode: pre-populated from getOne, submit calls update
  // -------------------------------------------------------------------------
  it('edit mode: pre-populates title from fetched meeting and calls meetingsApi.update on submit', async () => {
    const { meetingsApi } = await import('@features/meetings/api');
    vi.mocked(meetingsApi.getOne).mockResolvedValue(mockMeeting as unknown as never);
    vi.mocked(meetingsApi.update).mockResolvedValue({ ...mockMeeting } as unknown as never);

    const MeetingForm = (await import('@pages/strategy/meetings/MeetingForm')).default;
    const user = userEvent.setup();

    render(
      <MemoryRouter initialEntries={['/strategy/meetings/1/edit']}>
        <Routes>
          <Route path="/strategy/meetings/:id/edit" element={<MeetingForm mode="page" />} />
          <Route path="/strategy/meetings/:id" element={<div>View</div>} />
          <Route path="/strategy/meetings" element={<div>List</div>} />
        </Routes>
      </MemoryRouter>
    );

    // Wait until the form loads and the title field shows the fetched value.
    // The label includes a required asterisk span, so we match with exact: false.
    await waitFor(() => {
      const titleInput = screen.getByLabelText('meetings.meeting.fields.title', { exact: false }) as HTMLInputElement;
      expect(titleInput.value).toBe('Edit Me');
    });

    // Submit (no edits needed — just verify pre-population worked and update is called)
    const submitBtn = screen.getByRole('button', { name: 'meetings.meeting.form.submit_update' });
    await user.click(submitBtn);

    await waitFor(() => {
      expect(vi.mocked(meetingsApi.update)).toHaveBeenCalledWith(1, expect.objectContaining({ title: 'Edit Me' }));
    });
    expect(vi.mocked(meetingsApi.create)).not.toHaveBeenCalled();
  });
});
