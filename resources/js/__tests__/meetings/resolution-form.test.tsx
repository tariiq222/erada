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

vi.mock('@entities/user', () => ({
  usersApi: { getList: mocks.usersGetList },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test' } }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

import ResolutionForm from '@features/meetings/resolutions/ResolutionForm';

describe('ResolutionForm — kind mandatory + owner mandatory', () => {
  it('renders the kind radio with both options', async () => {
    render(<ResolutionForm mode="modal" meetingId={1} onSuccess={() => {}} onCancel={() => {}} />);
    expect(screen.getAllByText(/توصية/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/قرار/).length).toBeGreaterThan(0);
    await waitFor(() => expect(mocks.usersGetList).toHaveBeenCalled());
  });

  it('submits with kind=recommendation and owner_id', async () => {
    mocks.createForMeeting.mockClear();
    render(
      <ResolutionForm
        mode="modal"
        meetingId={7}
        initial={{ kind: 'recommendation', title: 'مخرج اجتماع', owner_id: 1 }}
        onSuccess={() => {}}
        onCancel={() => {}}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'إنشاء' }));

    await waitFor(() => expect(mocks.createForMeeting).toHaveBeenCalledWith(
      7,
      expect.objectContaining({
        meeting_id: 7,
        kind: 'recommendation',
        owner_id: 1,
        title: 'مخرج اجتماع',
      }),
    ));
  });

  it('loads organization users before an owner is selected', async () => {
    render(<ResolutionForm mode="modal" meetingId={7} onSuccess={() => {}} onCancel={() => {}} />);

    await waitFor(() => {
      expect(mocks.usersGetList).toHaveBeenCalled();
    });
  });

  it('does not expose approve/reject/adopt/deliberate in any field or label', async () => {
    const { container } = render(<ResolutionForm mode="modal" meetingId={1} onSuccess={() => {}} onCancel={() => {}} />);
    const html = container.innerHTML;
    expect(html).not.toMatch(/approve|اعتماد/i);
    expect(html).not.toMatch(/reject|رفض/i);
    expect(html).not.toMatch(/adopt/i);
    expect(html).not.toMatch(/deliberate/i);
    await waitFor(() => expect(mocks.usersGetList).toHaveBeenCalled());
  });
});
