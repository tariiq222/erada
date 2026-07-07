import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { NotificationsList } from '@pages/strategy/meetings/notifications/NotificationsList';
import { notificationsApi } from '@features/meetings/api';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@features/meetings/api', () => ({
  notificationsApi: {
    getAll: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    unreadCount: vi.fn(),
  },
}));

async function setup() {
  const result = render(
    <MemoryRouter>
      <NotificationsList />
    </MemoryRouter>,
  );
  await act(async () => { await Promise.resolve(); });
  return result;
}

describe('NotificationsList', () => {
  beforeEach(() => {
    vi.mocked(notificationsApi.getAll).mockResolvedValue({
      data: [
        {
          id: '1',
          type: 'App\\Notifications\\MeetingScheduled',
          data: {
            type: 'meeting_scheduled' as const,
            message: 'Test notification',
          },
          read_at: null,
          created_at: new Date().toISOString(),
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
    } as never);
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue({ unread: 1 } as never);
  });

  it('renders page title', async () => {
    await setup();
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  });

  it('renders notification item after loading', async () => {
    await setup();
    await waitFor(() =>
      expect(screen.getByText('Test notification')).toBeInTheDocument(),
    );
  });

  it('renders empty state when no notifications', async () => {
    vi.mocked(notificationsApi.getAll).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
    } as never);
    await setup();
    await waitFor(() =>
      expect(screen.getByText('meetings.notification.empty')).toBeInTheDocument(),
    );
  });

  it('calls markAllRead when mark-all-read button is clicked', async () => {
    vi.mocked(notificationsApi.markAllRead).mockResolvedValue(undefined as never);
    await setup();
    await waitFor(() => screen.getByText('Test notification'));
    const btn = screen.getByRole('button', {
      name: /meetings.notification.mark_all_read/i,
    });
    fireEvent.click(btn);
    await waitFor(() =>
      expect(notificationsApi.markAllRead).toHaveBeenCalledTimes(1),
    );
  });
});
