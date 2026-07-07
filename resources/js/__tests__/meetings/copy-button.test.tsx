import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent, waitFor } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => i18nKey,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}));
import CopyButton from '@shared/ui/CopyButton';

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn(), addToast: vi.fn(), removeToast: vi.fn(), toasts: [] }),
}));

Object.assign(navigator, {
  clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
});

describe('CopyButton', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders with default label', () => {
    const { getByRole } = render(<CopyButton text="MTG-2026-0001" />);
    expect(getByRole('button')).toBeInTheDocument();
  });

  it('copies text to clipboard on click', async () => {
    const { getByRole } = render(<CopyButton text="MTG-2026-0001" />);
    fireEvent.click(getByRole('button'));
    await waitFor(() => {
      expect(navigator.clipboard.writeText).toHaveBeenCalledWith('MTG-2026-0001');
    });
  });
});
