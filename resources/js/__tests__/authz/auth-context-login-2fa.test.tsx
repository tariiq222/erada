import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * P1 contract-fix: AuthContext.login must surface a 2FA challenge from the
 * /api/login backend response and must NOT mark the user as authenticated
 * before the second factor is verified. The backend never sets the
 * `auth_token` cookie or mints a Sanctum token on the password-only step
 * for 2FA-enabled accounts; the FE contract must reflect that.
 *
 * Backend source of truth (see app/Modules/Core/Http/Controllers/AuthController.php,
 * test tests/Feature/Auth/TwoFactorEnforcedTest.php):
 *   - 2FA required  -> { two_factor_required: true, user_id, pending_token, message }
 *   - normal login -> { user } + HttpOnly `auth_token` cookie
 */

const mockLogin = vi.fn();
const mockGetUser = vi.fn();
const mockSetAuthenticated = vi.fn();
const mockSetToken = vi.fn();
const mockClearAuth = vi.fn();

vi.mock('@shared/api/auth', () => ({
  authApi: {
    login: (...args: unknown[]) => mockLogin(...args),
    getUser: () => mockGetUser(),
    logout: vi.fn(),
  },
}));

vi.mock('@shared/api/client', () => ({
  api: {
    setAuthenticated: (authenticated: boolean) => mockSetAuthenticated(authenticated),
    setToken: (token: string | null) => mockSetToken(token),
    clearAuth: () => mockClearAuth(),
  },
}));

import { AuthProvider, useAuth } from '@shared/contexts/AuthContext';

interface LoginProbe {
  run: () => Promise<unknown>;
}

function LoginProbe({ probeRef }: { probeRef: { current: LoginProbe | null } }) {
  const { login, user, isAuthenticated } = useAuth();
  probeRef.current = {
    run: () => login('admin@example.test', 'secret-password'),
  };
  return (
    <>
      <div data-testid="user-name">{user?.name ?? 'signed-out'}</div>
      <div data-testid="auth-state">{isAuthenticated ? 'auth' : 'guest'}</div>
    </>
  );
}

const TWO_FA_BACKEND_RESPONSE = {
  two_factor_required: true,
  user_id: 9,
  pending_token: 'opaque-pending-token',
  message: 'كلمة المرور صحيحة. يلزم التحقق من رمز المصادقة الثنائية.',
};

const FULL_USER_BACKEND_RESPONSE = {
  user: {
    id: 7,
    name: 'Active User',
    email: 'active@example.test',
    department_id: null,
    phone: null,
    extension: null,
    job_title: null,
    is_active: true,
    is_super_admin: false,
    is_org_admin: false,
    access: {},
  },
};

describe('AuthContext.login 2FA contract (P1 contract-fix)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/login');
  });

  it('returns a normalized 2FA challenge result when backend flags two_factor_required', async () => {
    mockLogin.mockResolvedValueOnce(TWO_FA_BACKEND_RESPONSE);

    const probeRef: { current: LoginProbe | null } = { current: null };
    render(
      <AuthProvider>
        <LoginProbe probeRef={probeRef} />
      </AuthProvider>,
    );

    await waitFor(() => expect(probeRef.current).not.toBeNull());

    const result = (await probeRef.current!.run()) as {
      success: boolean;
      requiresTwoFactor?: boolean;
      pendingToken?: string;
      userId?: number;
      userName?: string;
    };

    // Backend's password-only 2FA challenge shape carries user_id +
    // pending_token only (see AuthController::login). userName is optional —
    // the standard backend response omits the `user` projection at this
    // stage, but the FE carries the field through so callers that pre-load
    // the user's name (e.g. by email lookup) can display it.
    expect(result.success).toBe(false);
    expect(result.requiresTwoFactor).toBe(true);
    expect(result.pendingToken).toBe('opaque-pending-token');
    expect(result.userId).toBe(9);
    expect(result.userName).toBeUndefined();

    // Auth state must NOT be promoted — only /api/2fa/verify or a non-2FA
    // login success is allowed to mark this user as authenticated.
    expect(mockSetAuthenticated).not.toHaveBeenCalledWith(true);
    expect(mockSetToken).not.toHaveBeenCalled();
    expect(mockGetUser).not.toHaveBeenCalled();
    expect(screen.getByTestId('user-name')).toHaveTextContent('signed-out');
    expect(screen.getByTestId('auth-state')).toHaveTextContent('guest');
  });

  it('promotes authenticated state and returns success for a non-2FA login', async () => {
    mockLogin.mockResolvedValueOnce(FULL_USER_BACKEND_RESPONSE);

    const probeRef: { current: LoginProbe | null } = { current: null };
    render(
      <AuthProvider>
        <LoginProbe probeRef={probeRef} />
      </AuthProvider>,
    );

    await waitFor(() => expect(probeRef.current).not.toBeNull());

    const result = (await probeRef.current!.run()) as { success: boolean };

    expect(result.success).toBe(true);
    expect(mockSetAuthenticated).toHaveBeenCalledWith(true);
    await waitFor(() =>
      expect(screen.getByTestId('user-name')).toHaveTextContent('Active User'),
    );
    await waitFor(() =>
      expect(screen.getByTestId('auth-state')).toHaveTextContent('auth'),
    );
  });

  it('does not throw when the 2FA challenge response omits the user name', async () => {
    mockLogin.mockResolvedValueOnce({
      two_factor_required: true,
      user_id: 4,
      pending_token: 'opaque-only-token',
      message: '...',
    });

    const probeRef: { current: LoginProbe | null } = { current: null };
    render(
      <AuthProvider>
        <LoginProbe probeRef={probeRef} />
      </AuthProvider>,
    );

    await waitFor(() => expect(probeRef.current).not.toBeNull());

    const result = (await probeRef.current!.run()) as {
      success: boolean;
      requiresTwoFactor?: boolean;
      pendingToken?: string;
      userId?: number;
      userName?: string;
    };

    expect(result.success).toBe(false);
    expect(result.requiresTwoFactor).toBe(true);
    expect(result.pendingToken).toBe('opaque-only-token');
    expect(result.userId).toBe(4);
    expect(result.userName).toBeUndefined();
    expect(mockSetAuthenticated).not.toHaveBeenCalledWith(true);
  });
});
