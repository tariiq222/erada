import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  getOne: vi.fn(),
  cancel: vi.fn(),
  delete: vi.fn(),
  toast: vi.fn(),
  navigate: vi.fn(),
}));

vi.mock('@features/meetings/api', () => ({
  meetingsApi: {
    getOne: mocks.getOne,
    cancel: mocks.cancel,
    delete: mocks.delete,
    start: vi.fn(), complete: vi.fn(), updateMinutes: vi.fn(),
  },
}));
vi.mock('@shared/contexts/AuthContext', () => ({ useAuth: () => ({ user: { id: 1 } }) }));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: mocks.toast }) }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));
vi.mock('react-router-dom', () => ({ useNavigate: () => mocks.navigate }));

import { useMeetingView } from '@pages/strategy/meetings/view/useMeetingView';

describe('useMeetingView destructive actions', () => {
  it('surfaces plain API messages for cancel and delete without rejecting', async () => {
    mocks.getOne.mockResolvedValue({ id: 1, title: 'اجتماع' });
    mocks.cancel.mockRejectedValue({ message: 'لا يمكن إلغاء الاجتماع' });
    mocks.delete.mockRejectedValue({ message: 'لا يمكن حذف الاجتماع' });

    const { result } = renderHook(() => useMeetingView('1'));
    await waitFor(() => expect(result.current.meeting?.id).toBe(1));

    await act(async () => { await expect(result.current.cancel()).resolves.toBeUndefined(); });
    await act(async () => { await expect(result.current.remove()).resolves.toBeUndefined(); });

    expect(mocks.toast).toHaveBeenCalledWith('error', 'لا يمكن إلغاء الاجتماع');
    expect(mocks.toast).toHaveBeenCalledWith('error', 'لا يمكن حذف الاجتماع');
  });
});
