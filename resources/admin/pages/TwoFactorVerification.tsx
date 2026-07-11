import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconKey, IconShieldCheck } from '@tabler/icons-react';
import { twoFactorApi } from '@shared/api/twoFactor';
import { api } from '@shared/api/client';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { Button } from '@shared/ui/Button';
import { Card, CardContent } from '@shared/ui/Card';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';

interface TwoFactorLocationState {
  pendingToken: string;
  userId: number;
  userName?: string;
  returnTo?: string;
}

export function TwoFactorVerification() {
  const { t } = useTranslation();
  const { direction } = useLocale();
  const { isAuthenticated, refreshUser } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const state = location.state as TwoFactorLocationState | null;
  const returnTo = getSafeAdminReturnPath(state?.returnTo);
  const [digits, setDigits] = useState(['', '', '', '', '', '']);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const inputRefs = useRef<Array<HTMLInputElement | null>>([]);

  useEffect(() => {
    inputRefs.current[0]?.focus();
  }, []);

  if (isAuthenticated) {
    return <Navigate to={returnTo} replace />;
  }

  if (!state?.pendingToken || !state.userId) {
    return <Navigate to={`/login?returnTo=${encodeURIComponent(returnTo)}`} replace />;
  }

  const updateDigit = (index: number, value: string) => {
    if (!/^\d*$/.test(value)) return;
    const nextDigits = [...digits];
    nextDigits[index] = value.slice(-1);
    setDigits(nextDigits);
    if (value && index < 5) inputRefs.current[index + 1]?.focus();
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const code = digits.join('');
    if (code.length !== 6) {
      setError(t('auth.enter_6_digit_code'));
      return;
    }

    setError('');
    setSubmitting(true);
    try {
      await twoFactorApi.verify(state.userId, code, state.pendingToken);
      api.setAuthenticated(true);
      await refreshUser();
      navigate(returnTo, { replace: true });
    } catch (caught: unknown) {
      const message = caught instanceof Error ? caught.message : t('auth.invalid_verification_code');
      setError(message);
      setDigits(['', '', '', '', '', '']);
      inputRefs.current[0]?.focus();
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-[var(--surface-subtle)] p-4" dir={direction}>
      <div className="w-full max-w-md">
        <div className="mb-7 text-center">
          <span className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--accent-default)]">
            <IconShieldCheck className="h-8 w-8 text-[var(--text-inverse)]" aria-hidden="true" />
          </span>
          <h1 className="text-3xl font-bold text-[var(--text-primary)]">{t('auth.two_factor_title')}</h1>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            {state.userName
              ? t('auth.two_factor_welcome', { name: state.userName })
              : t('auth.two_factor_enter_code_description')}
          </p>
        </div>
        <Card className="border-[var(--border-default)] bg-[var(--surface-base)] shadow-lg">
          <CardContent className="p-6 sm:p-8">
            <div className="mb-5 flex items-center justify-center gap-2 text-[var(--text-primary)]">
              <IconKey className="h-5 w-5 text-[var(--accent-default)]" aria-hidden="true" />
              <p className="font-semibold">{t('auth.enter_verification_code')}</p>
            </div>
            {error && <p role="alert" className="mb-4 text-center text-sm text-[var(--status-danger-text)]">{error}</p>}
            <form onSubmit={handleSubmit}>
              <div className="mb-6 flex justify-center gap-2" dir="ltr" role="group" aria-label={t('auth.enter_verification_code')}>
                {digits.map((digit, index) => (
                  <input
                    key={index}
                    ref={(element) => { inputRefs.current[index] = element; }}
                    value={digit}
                    onChange={(event) => updateDigit(index, event.target.value)}
                    aria-label={`${t('auth.enter_verification_code')} ${index + 1}`}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={1}
                    className="h-13 w-11 rounded-lg border border-[var(--border-default)] bg-[var(--surface-muted)] text-center text-xl font-bold text-[var(--text-primary)] focus:border-[var(--accent-default)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-subtle)]"
                    disabled={submitting}
                  />
                ))}
              </div>
              <Button type="submit" loading={submitting} className="h-11 w-full">
                {submitting ? t('auth.verifying') : t('auth.verify')}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
