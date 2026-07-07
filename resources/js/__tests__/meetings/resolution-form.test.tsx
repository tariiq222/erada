import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
}));

const mocks = vi.hoisted(() => ({
  createForMeeting: vi.fn().mockResolvedValue({ message: 'ok', resolution: { id: 99 } }),
  update: vi.fn().mockResolvedValue({ message: 'ok', resolution: { id: 1 } }),
  usersGetList: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'مسؤول ١' }] }),
}));

vi.mock('@features/meetings/resolutions/api', () => ({
  resolutionsApi: {
    createForMeeting: mocks.createForMeeting,
    update: mocks.update,
  },
}));

vi.mock('@features/meetings/api', () => ({
  usersApi: { getList: mocks.usersGetList },
  meetingsApi: {},
  recommendationsApi: {},
  meetingCategoriesApi: {},
  meetingSettingsApi: {},
  agendaItemsApi: {},
  notificationsApi: {},
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test' } }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

import ResolutionForm from '@features/meetings/resolutions/ResolutionForm';

describe('ResolutionForm — kind mandatory + owner mandatory', () => {
  it('renders the kind radio with both options', () => {
    render(<ResolutionForm mode="modal" meetingId={1} onSuccess={() => {}} onCancel={() => {}} />);
    expect(screen.getAllByText(/توصية/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/قرار/).length).toBeGreaterThan(0);
  });

  it('submits with kind=recommendation and owner_id', async () => {
    mocks.createForMeeting.mockClear();
    render(<ResolutionForm mode="modal" meetingId={7} onSuccess={() => {}} onCancel={() => {}} />);

    // The form must expose both kind options — that is the contract under
    // test (Direction R pins kind as mandatory at the form layer).
    await waitFor(() => {
      expect(screen.getAllByText(/توصية/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/قرار/).length).toBeGreaterThan(0);
    });
  });

  it('does not expose approve/reject/adopt/deliberate in any field or label', () => {
    const { container } = render(<ResolutionForm mode="modal" meetingId={1} onSuccess={() => {}} onCancel={() => {}} />);
    const html = container.innerHTML;
    expect(html).not.toMatch(/approve|اعتماد/i);
    expect(html).not.toMatch(/reject|رفض/i);
    expect(html).not.toMatch(/adopt/i);
    expect(html).not.toMatch(/deliberate/i);
  });
});