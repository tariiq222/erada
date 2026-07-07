import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { NotificationBell } from '@features/meetings/components/NotificationBell';
import { notificationsApi } from '@features/meetings/api';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts?.count !== undefined ? `${key}:${opts.count}` : key,
  }),
}));

vi.mock('@features/meetings/api', () => ({
  notificationsApi: {
    unreadCount: vi.fn(),
    getAll: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
  },
}));

function setup() {
  return render(
    <MemoryRouter>
      <NotificationBell />
    </MemoryRouter>,
  );
}

describe('NotificationBell', () => {
  beforeEach(() => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue({ unread: 2 } as never);
    vi.mocked(notificationsApi.getAll).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
    } as never);
  });

  it('renders bell button', async () => {
    setup();
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByRole('button', { name: /meetings.notification.label/i })).toBeInTheDocument();
  });

  it('shows unread badge when count > 0', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByText(/meetings.notification.unread_summary/i)).toBeInTheDocument(),
    );
  });

  it('opens dropdown on click', async () => {
    setup();
    await act(async () => { await Promise.resolve(); });
    const bell = screen.getByRole('button', { name: /meetings.notification.label/i });
    fireEvent.click(bell);
    await waitFor(() =>
      expect(screen.getByRole('dialog')).toBeInTheDocument(),
    );
  });
});
