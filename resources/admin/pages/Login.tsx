import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconBolt, IconLock, IconLogin, IconMail, IconShieldLock } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import { Button } from '@shared/ui/Button';
import { Card, CardContent } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';

export function Login() {
  const { t } = useTranslation();
  const { direction } = useLocale();
  const { settings } = useSystemSettings();
  const { login, isAuthenticated, isLoading } = useAuth();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const returnTo = getSafeAdminReturnPath(searchParams.get('returnTo'));
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const completeLogin = async (nextEmail: string, nextPassword: string) => {
    setError('');
    setSubmitting(true);

    try {
      const result = await login(nextEmail, nextPassword);

      if (
        result.requiresTwoFactor &&
        result.pendingToken &&
        result.userId
      ) {
        navigate('/verify-2fa', {
          replace: true,
          state: {
            pendingToken: result.pendingToken,
            userId: result.userId,
            userName: result.userName,
            returnTo,
          },
        });
        return;
      }

      navigate(returnTo, { replace: true });
    } catch (caught: unknown) {
      const message = caught instanceof Error ? caught.message : t('auth.login_failed');
      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  if (isLoading) {
    return <p role="status">{t('common.loading')}</p>;
  }

  if (isAuthenticated) {
    return <Navigate to={returnTo} replace />;
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    await completeLogin(email, password);
  };

  return (
    <main
      className="flex min-h-screen items-center justify-center bg-[var(--surface-subtle)] p-4"
      dir={direction}
    >
      <div className="w-full max-w-md">
        <div className="mb-7 text-center">
          <span className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl border border-[var(--border-default)] bg-[var(--surface-raised)] shadow-sm">
            <IconShieldLock className="h-8 w-8 text-[var(--accent-default)]" aria-hidden="true" />
          </span>
          <p className="text-sm font-semibold text-[var(--accent-default)]">{t('admin.shell.brand')}</p>
          <h1 className="mt-2 text-3xl font-bold text-[var(--text-primary)]">{t('auth.login')}</h1>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">{settings?.name || t('common.app_name')}</p>
        </div>

        <Card className="border-[var(--border-default)] bg-[var(--surface-base)] shadow-lg">
          <CardContent className="p-6 sm:p-8">
            {error && (
              <p role="alert" className="mb-5 rounded-lg bg-[var(--status-danger-subtle)] p-3 text-sm text-[var(--status-danger-text)]">
                {error}
              </p>
            )}
            <form className="space-y-5" onSubmit={handleSubmit}>
              <Input
                id="admin-email"
                type="email"
                autoComplete="email"
                label={t('auth.email_label')}
                leftIcon={<IconMail className="h-5 w-5" />}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                autoFocus
              />
              <Input
                id="admin-password"
                type="password"
                autoComplete="current-password"
                label={t('auth.password_label')}
                leftIcon={<IconLock className="h-5 w-5" />}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
              <Button
                type="submit"
                loading={submitting}
                leftIcon={<IconLogin className="h-5 w-5" />}
                className="h-11 w-full"
              >
                {submitting ? t('auth.logging_in') : t('auth.login_button')}
              </Button>
              {import.meta.env.DEV && (
                <Button
                  type="button"
                  variant="secondary"
                  loading={submitting}
                  leftIcon={<IconBolt className="h-5 w-5" />}
                  className="h-11 w-full"
                  onClick={() => void completeLogin('admin@admin.com', 'password')}
                >
                  {t('auth.dev_quick_login')}
                </Button>
              )}
            </form>
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
