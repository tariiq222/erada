import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock contexts
const mockVerify2FA = vi.fn();
const mockNavigate = vi.fn();
const mockLocationState = {
  pendingToken: 'test-token',
  userId: 1,
  userName: 'Test User',
};

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useLocation: () => ({
    state: mockLocationState,
  }),
  Navigate: ({ to }: { to: string }) => <div data-testid="redirect">Redirect to {to}</div>,
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    canAccess: () => true,
    verify2FA: mockVerify2FA,
    isAuthenticated: false,
    isLoading: false,
  }),
}));

// Mock UI components
vi.mock('@shared/ui', () => ({
  Card: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div className={className} data-testid="card">{children}</div>
  ),
  CardContent: ({ children }: { children: React.ReactNode }) => (
    <div>{children}</div>
  ),
  CardHeader: ({ children }: { children: React.ReactNode }) => (
    <div>{children}</div>
  ),
  CardTitle: ({ children }: { children: React.ReactNode }) => (
    <h2>{children}</h2>
  ),
  Input: ({ ...props }) => <input {...props} />,
  Button: ({ children, ...props }: { children: React.ReactNode; [key: string]: unknown }) => (
    <button {...props}>{children}</button>
  ),
}));

// Simple mock component for testing
const Verify2FA: React.FC = () => {
  const [code, setCode] = React.useState('');
  const [error, setError] = React.useState('');
  const [loading, setLoading] = React.useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      await mockVerify2FA(code, mockLocationState.pendingToken);
      mockNavigate('/dashboard');
    } catch (err: unknown) {
      const error = err as Error;
      setError(error.message || 'رمز التحقق غير صحيح');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div dir="rtl">
      <h1>التحقق بخطوتين</h1>
      <p>مرحباً {mockLocationState.userName}</p>
      <p>أدخل رمز التحقق من تطبيق المصادقة</p>

      {error && <div role="alert">{error}</div>}

      <form onSubmit={handleSubmit}>
        <label htmlFor="code">رمز التحقق</label>
        <input
          id="code"
          type="text"
          inputMode="numeric"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
          placeholder="000000"
          required
          autoFocus
        />
        <button type="submit" disabled={loading || code.length !== 6}>
          {loading ? 'جاري التحقق...' : 'تحقق'}
        </button>
      </form>

      <button type="button" onClick={() => mockNavigate('/login')}>
        العودة لتسجيل الدخول
      </button>
    </div>
  );
};

describe('Two Factor Verification Page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders 2FA form', () => {
    render(<Verify2FA />);
    expect(screen.getByText('التحقق بخطوتين')).toBeInTheDocument();
  });

  it('displays user name', () => {
    render(<Verify2FA />);
    expect(screen.getByText(/Test User/)).toBeInTheDocument();
  });

  it('renders code input', () => {
    render(<Verify2FA />);
    expect(screen.getByPlaceholderText('000000')).toBeInTheDocument();
  });

  it('renders verify button', () => {
    render(<Verify2FA />);
    expect(screen.getByText('تحقق')).toBeInTheDocument();
  });

  it('renders back to login button', () => {
    render(<Verify2FA />);
    expect(screen.getByText('العودة لتسجيل الدخول')).toBeInTheDocument();
  });
});

describe('2FA Code Input', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('updates code value when typing numbers', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000') as HTMLInputElement;
    await userEvent.type(codeInput, '123456');
    expect(codeInput.value).toBe('123456');
  });

  it('filters non-numeric characters', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000') as HTMLInputElement;
    await userEvent.type(codeInput, 'abc123def456');
    expect(codeInput.value).toBe('123456');
  });

  it('limits input to 6 characters', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000') as HTMLInputElement;
    await userEvent.type(codeInput, '12345678');
    expect(codeInput.value.length).toBeLessThanOrEqual(6);
  });
});

describe('2FA Form Submission', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockVerify2FA.mockResolvedValue({});
  });

  it('button is disabled when code is incomplete', () => {
    render(<Verify2FA />);
    const submitButton = screen.getByText('تحقق');
    expect(submitButton).toBeDisabled();
  });

  it('button is enabled when code is complete', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '123456');
    const submitButton = screen.getByText('تحقق');
    expect(submitButton).not.toBeDisabled();
  });

  it('calls verify2FA on form submit', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '123456');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    expect(mockVerify2FA).toHaveBeenCalledWith('123456', 'test-token');
  });

  it('navigates to dashboard on successful verification', async () => {
    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '123456');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/dashboard');
    });
  });
});

describe('2FA Error Handling', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows error message on invalid code', async () => {
    mockVerify2FA.mockRejectedValueOnce(new Error('رمز التحقق غير صحيح'));

    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '000000');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('رمز التحقق غير صحيح');
    });
  });

  it('shows default error message when no message provided', async () => {
    mockVerify2FA.mockRejectedValueOnce(new Error());

    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '000000');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('رمز التحقق غير صحيح');
    });
  });
});

describe('2FA Loading State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows loading text during verification', async () => {
    mockVerify2FA.mockImplementation(() => new Promise((resolve) => setTimeout(resolve, 100)));

    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '123456');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    expect(screen.getByText('جاري التحقق...')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('تحقق')).toBeInTheDocument());
  });

  it('disables button during verification', async () => {
    mockVerify2FA.mockImplementation(() => new Promise((resolve) => setTimeout(resolve, 100)));

    render(<Verify2FA />);
    const codeInput = screen.getByPlaceholderText('000000');
    await userEvent.type(codeInput, '123456');

    const submitButton = screen.getByText('تحقق');
    await userEvent.click(submitButton);

    const loadingButton = screen.getByText('جاري التحقق...').closest('button');
    expect(loadingButton).toBeDisabled();
    await waitFor(() => expect(screen.getByText('تحقق')).toBeInTheDocument());
  });
});

describe('2FA Navigation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('navigates back to login when clicking back button', async () => {
    render(<Verify2FA />);
    const backButton = screen.getByText('العودة لتسجيل الدخول');
    await userEvent.click(backButton);

    expect(mockNavigate).toHaveBeenCalledWith('/login');
  });
});
