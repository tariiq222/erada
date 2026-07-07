import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock localStorage
const localStorageMock = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: vi.fn((key: string) => store[key] || null),
    setItem: vi.fn((key: string, value: string) => { store[key] = value; }),
    removeItem: vi.fn((key: string) => { delete store[key]; }),
    clear: vi.fn(() => { store = {}; }),
    get length() { return Object.keys(store).length; },
    key: vi.fn((index: number) => Object.keys(store)[index] || null),
  };
})();

Object.defineProperty(window, 'localStorage', { value: localStorageMock });

// Mock API module
const mockGetUser = vi.fn();
const mockLogin = vi.fn();
const mockLogout = vi.fn();
const mockSetAuthenticated = vi.fn();
const mockClearAuth = vi.fn();
const mockSetToken = vi.fn();

vi.mock('@shared/api/auth', () => ({
  authApi: {
    getUser: () => mockGetUser(),
    login: (email: string, password: string) => mockLogin(email, password),
    logout: () => mockLogout(),
  },
}));
vi.mock('@shared/api/client', () => ({
  api: {
    getToken: () => localStorageMock.getItem('auth_token'),
    setToken: (token: string | null) => {
      mockSetToken(token);
      if (token) {
        localStorageMock.setItem('auth_token', token);
      } else {
        localStorageMock.removeItem('auth_token');
      }
    },
    setAuthenticated: (authenticated: boolean) => mockSetAuthenticated(authenticated),
    clearAuth: () => {
      mockClearAuth();
      localStorageMock.removeItem('auth_token');
    },
  },
}));

// Import after mocking
import { AuthProvider, useAuth } from '@shared/contexts/AuthContext';

// Test component to access context
const TestConsumer: React.FC = () => {
  const {
    user,
    isLoading,
    isAuthenticated,
    login,
    logout,
    hasRole,
    hasPermission,
    isSuperAdmin,
  } = useAuth();

  return (
    <div>
      <div data-testid="loading">{isLoading ? 'loading' : 'ready'}</div>
      <div data-testid="authenticated">{isAuthenticated ? 'yes' : 'no'}</div>
      <div data-testid="user">{user?.name || 'null'}</div>
      <div data-testid="hasAdminRole">{hasRole('admin') ? 'yes' : 'no'}</div>
      <div data-testid="hasEditPermission">{hasPermission('projects.edit') ? 'yes' : 'no'}</div>
      <div data-testid="isSuperAdmin">{isSuperAdmin() ? 'yes' : 'no'}</div>
      <button onClick={() => login('test@test.com', 'password')}>Login</button>
      <button onClick={() => logout()}>Logout</button>
    </div>
  );
};

describe('AuthContext', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorageMock.clear();
  });

  afterEach(() => {
    vi.clearAllMocks();
    localStorageMock.clear();
  });

  it('throws error when useAuth is used outside AuthProvider', () => {
    // Suppress console.error for this test
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    expect(() => {
      render(<TestConsumer />);
    }).toThrow('useAuth must be used within an AuthProvider');

    consoleSpy.mockRestore();
  });

  it('shows loading state initially when token exists', async () => {
    localStorageMock.setItem('auth_token', 'existing-token');
    mockGetUser.mockReturnValue(new Promise(() => {})); // Never resolves

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    expect(screen.getByTestId('loading')).toHaveTextContent('loading');
  });

  it('shows ready state when no token exists', async () => {
    // No token - getUser() will fail (401), causing context to clear auth and become ready
    mockGetUser.mockRejectedValueOnce({ status: 401, message: 'Unauthenticated' });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('ready');
    });
  });

  it('loads user when token exists', async () => {
    localStorageMock.setItem('auth_token', 'valid-token');
    mockGetUser.mockResolvedValue({
      user: {
        id: 1,
        name: 'Test User',
        email: 'test@test.com',
        roles: ['admin'],
        // Phase 9.3: legacy `permissions[]` removed from /api/auth/me;
        // canonical `access` shape is the source of truth.
        access: { projects: { edit: true } },
      },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('ready');
      expect(screen.getByTestId('user')).toHaveTextContent('Test User');
      expect(screen.getByTestId('authenticated')).toHaveTextContent('yes');
    });
  });

  it('clears auth if getUser fails', async () => {
    localStorageMock.setItem('auth_token', 'invalid-token');
    mockGetUser.mockRejectedValue(new Error('Unauthorized'));

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(mockClearAuth).toHaveBeenCalled();
      expect(screen.getByTestId('authenticated')).toHaveTextContent('no');
    });
  });

  it('login sets user and token', async () => {
    // No token initially
    mockLogin.mockResolvedValue({
      user: { id: 1, name: 'Logged In User', email: 'user@test.com', roles: [], permissions: [] },
      token: 'new-token',
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('ready');
    });

    await userEvent.click(screen.getByText('Login'));

    await waitFor(() => {
      expect(mockLogin).toHaveBeenCalledWith('test@test.com', 'password');
      expect(mockSetToken).toHaveBeenCalledWith('new-token');
      expect(mockSetAuthenticated).toHaveBeenCalledWith(true);
      expect(screen.getByTestId('user')).toHaveTextContent('Logged In User');
    });
  });

  it('logout clears user and token', async () => {
    localStorageMock.setItem('auth_token', 'valid-token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Test', roles: [], permissions: [] },
    });
    mockLogout.mockResolvedValue({ message: 'Logged out' });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent('Test');
    });

    await userEvent.click(screen.getByText('Logout'));

    await waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockClearAuth).toHaveBeenCalled();
      expect(screen.getByTestId('user')).toHaveTextContent('null');
      expect(screen.getByTestId('authenticated')).toHaveTextContent('no');
    });
  });

  it('hasRole returns true for matching role', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Test', roles: ['admin', 'user'], permissions: [] },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('hasAdminRole')).toHaveTextContent('yes');
    });
  });

  it('hasRole returns true for super_admin', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Super', roles: ['super_admin'], permissions: [] },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      // super_admin should have access to admin role
      expect(screen.getByTestId('hasAdminRole')).toHaveTextContent('yes');
    });
  });

  it('hasPermission returns true for matching permission', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: {
        id: 1,
        name: 'Test',
        roles: [],
        // Phase 9.3: canonical access map; hasPermission reads the
        // access-bridge which resolves legacy strings through LEGACY_PERMISSION_TO_CAPABILITY.
        access: { projects: { edit: true } },
      },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('hasEditPermission')).toHaveTextContent('yes');
    });
  });

  it('hasPermission returns true for super_admin', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Super', roles: ['super_admin'], permissions: [] },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      // super_admin should have all permissions
      expect(screen.getByTestId('hasEditPermission')).toHaveTextContent('yes');
    });
  });

  it('isSuperAdmin returns true for super_admin role', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Super', roles: ['super_admin'], permissions: [] },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('isSuperAdmin')).toHaveTextContent('yes');
    });
  });

  it('isSuperAdmin returns false for regular user', async () => {
    localStorageMock.setItem('auth_token', 'token');
    mockGetUser.mockResolvedValue({
      user: { id: 1, name: 'Regular', roles: ['admin'], permissions: [] },
    });

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('isSuperAdmin')).toHaveTextContent('no');
    });
  });
});
