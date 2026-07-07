import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import RegisterPage from '@pages/auth/RegisterPage';

const mockRegister = vi.fn();
const mockRefreshUser = vi.fn();
const mockNavigate = vi.fn();
const mockShowToast = vi.fn();
const mockIsAuthenticated = vi.fn(() => false);
const mockAuthLoading = vi.fn(() => false);

vi.mock('@features/auth/registrationApi', () => ({
  registrationApi: {
    register: (payload: unknown) => mockRegister(payload),
    forgot: vi.fn(),
    reset: vi.fn(),
  },
}));

vi.mock('@shared/ui/Toast', async () => {
  const actual = await vi.importActual<typeof import('@shared/ui/Toast')>('@shared/ui/Toast');
  return {
    ...actual,
    useToast: () => ({ showToast: mockShowToast }),
  };
});

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    isAuthenticated: mockIsAuthenticated(),
    isLoading: mockAuthLoading(),
    refreshUser: mockRefreshUser,
    user: null,
  }),
}));

vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  useSystemSettings: () => ({
    settings: { name: 'Erada Test' },
    loading: false,
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({
    direction: 'rtl',
    locale: 'ar',
  }),
}));

vi.mock('@shared/ui/LanguageSwitcher', () => ({
  default: () => <div data-testid="lang-switcher" />,
}));

vi.mock('@shared/ui/ThemeSwitcher', () => ({
  default: () => <div data-testid="theme-switcher" />,
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

function renderPage() {
  return render(
    <MemoryRouter>
      <RegisterPage />
    </MemoryRouter>,
  );
}

describe('RegisterPage (simplified flow)', () => {
  beforeEach(() => {
    mockRegister.mockReset();
    mockNavigate.mockReset();
    mockRefreshUser.mockReset();
    mockIsAuthenticated.mockReturnValue(false);
    mockAuthLoading.mockReturnValue(false);
    mockRegister.mockResolvedValue({ data: { user: { id: 1 } } });
  });

  function getSubmitButton(): HTMLButtonElement {
    const form = document.querySelector('form') as HTMLFormElement;
    return form.querySelector('button[type="submit"]') as HTMLButtonElement;
  }

  it('renders a single-step form with all required fields', () => {
    renderPage();
    expect(document.getElementById('register-name')).toBeInTheDocument();
    expect(document.getElementById('register-email')).toBeInTheDocument();
    expect(document.getElementById('register-password')).toBeInTheDocument();
    expect(document.getElementById('register-password-confirmation')).toBeInTheDocument();
    // Single submit button — no step indicators
    expect(getSubmitButton()).toBeInTheDocument();
    // i18n key (when test setup has no translations loaded) or the
    // translated Arabic text. Either is acceptable — the form must NOT
    // contain any of the old step buttons (resend, change email, verify).
    const submitText = getSubmitButton().textContent ?? '';
    expect(submitText).toMatch(/إنشاء حساب|registration\.register_button/);
  });

  it('submits the form to /api/register and redirects to the dashboard on success', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.type(document.getElementById('register-name') as HTMLInputElement, 'New User');
    await user.type(document.getElementById('register-email') as HTMLInputElement, 'new@erada.test');
    await user.type(document.getElementById('register-password') as HTMLInputElement, 'Str0ng!Passw0rd9');
    await user.type(document.getElementById('register-password-confirmation') as HTMLInputElement, 'Str0ng!Passw0rd9');
    await user.click(getSubmitButton());

    await waitFor(() => {
      expect(mockRegister).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'New User',
          email: 'new@erada.test',
          password: 'Str0ng!Passw0rd9',
          password_confirmation: 'Str0ng!Passw0rd9',
        }),
      );
    });
    expect(mockRefreshUser).toHaveBeenCalled();
    expect(mockShowToast).toHaveBeenCalledWith('success', expect.any(String));
    expect(mockNavigate).toHaveBeenCalledWith('/dashboard');
  });

  it('shows the server-side error when registration fails', async () => {
    const user = userEvent.setup();
    mockRegister.mockRejectedValueOnce(new Error('البريد الإلكتروني مستخدم من قبل.'));
    renderPage();

    await user.type(document.getElementById('register-name') as HTMLInputElement, 'New');
    await user.type(document.getElementById('register-email') as HTMLInputElement, 'dup@erada.test');
    await user.type(document.getElementById('register-password') as HTMLInputElement, 'Str0ng!Passw0rd9');
    await user.type(document.getElementById('register-password-confirmation') as HTMLInputElement, 'Str0ng!Passw0rd9');
    await user.click(getSubmitButton());

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('البريد الإلكتروني مستخدم');
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('does not include any OTP or invite-token field', () => {
    renderPage();
    // No 6-digit code input, no invite token textarea, no "resend code" link,
    // no "change email" link — those were the markers of the old flow.
    expect(document.getElementById('register-code')).not.toBeInTheDocument();
    expect(document.getElementById('register-invite-token')).not.toBeInTheDocument();
    expect(screen.queryByText(/تغيير البريد/)).not.toBeInTheDocument();
    expect(screen.queryByText(/إعادة الإرسال/)).not.toBeInTheDocument();
  });
});
