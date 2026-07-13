import React, { useState } from 'react';
import { Link, useNavigate, Navigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { Card, CardContent, Input, Button } from '@shared/ui';
import LanguageSwitcher from '@shared/ui/LanguageSwitcher';
import ThemeSwitcher from '@shared/ui/ThemeSwitcher';
import {IconMail, IconLock, IconLogin as LoginIcon, IconLoader, IconEye, IconEyeOff} from '@tabler/icons-react';

const DevQuickLogin = import.meta.env.DEV
  ? React.lazy(() =>
      import('./devLogin').then((module) => ({ default: module.DevQuickLogin })),
    )
  : null;

const IconLogin: React.FC = () => {
  const { t } = useTranslation();
  const { login, isAuthenticated, isLoading, refreshUser } = useAuth();
  const { settings: systemSettings } = useSystemSettings();
  const { direction } = useLocale();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  if (isLoading) {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center">
        <div className="text-center">
          <h1 className="sr-only">{t('auth.login')}</h1>
          <img src="/images/logo.png" alt={t('common.app_name')} className="h-20 w-20 mx-auto mb-4" />
          <IconLoader className="h-6 w-6 text-[var(--accent-default)] animate-spin mx-auto mb-3" />
          <p className="text-[var(--text-secondary)] font-medium">{t('common.loading')}</p>
        </div>
      </div>
    );
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const doLogin = async (loginEmail: string, loginPassword: string) => {
    setError('');
    setLoading(true);

    try {
      const result = await login(loginEmail, loginPassword);

      // 2FA challenge: backend accepted the password but did NOT issue the
      // `auth_token` cookie. Surface the pending token to /verify-2fa so the
      // second factor can complete the session.
      if (result.requiresTwoFactor && result.pendingToken && result.userId) {
        navigate('/verify-2fa', {
          replace: true,
          state: {
            pendingToken: result.pendingToken,
            userId: result.userId,
            userName: result.userName,
          },
        });
        return;
      }

      await refreshUser();
      navigate('/dashboard');
    } catch (err: any) {
      setError(err.message || t('auth.login_failed'));
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await doLogin(email, password);
  };

  return (
    <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center p-4" dir={direction}>
      {/* أزرار تغيير الوضع واللغة */}
      <div className="absolute top-4 start-4 z-20 flex items-center gap-2">
        <ThemeSwitcher />
        <LanguageSwitcher />
      </div>

      <main className="w-full max-w-md">
        {/* Logo */}
        <div className="text-center mb-8">
          <img src="/images/logo.png" alt={t('common.app_name')} className="h-24 w-24 mx-auto mb-6" />
          <h1 className="text-3xl font-bold text-[var(--text-primary)] mb-2">{systemSettings?.name || t('common.app_name')}</h1>
          {systemSettings?.code && (
            <p className="text-[var(--text-secondary)] font-medium">{systemSettings.code}</p>
          )}
        </div>

        <Card className="bg-[var(--surface-base)] border-[var(--border-default)] shadow-md">
          <CardContent className="p-8">
            <h2 className="text-xl font-bold text-[var(--text-primary)] text-center mb-6">
              {t('auth.login')}
            </h2>

            {error && (
              <div role="alert" aria-live="polite" className="mb-6 p-4 rounded-lg bg-[var(--status-danger-subtle)] border border-[var(--status-danger)]/30">
                <p className="text-[var(--status-danger-text)] text-sm text-center">{error}</p>
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-5">
              <Input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                label={t('auth.email_label')}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="example@domain.com"
                leftIcon={<IconMail className="h-5 w-5" />}
                required
                autoFocus
              />

              <div className="relative">
                <Input
                  id="password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  label={t('auth.password_label')}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  leftIcon={<IconLock className="h-5 w-5" />}
                  className="pe-10"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label={showPassword ? t('auth.hide_password') : t('auth.show_password')}
                  className="absolute end-0 bottom-0 h-[42px] flex items-center pe-3 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors"
                >
                  {showPassword ? <IconEyeOff className="h-5 w-5" /> : <IconEye className="h-5 w-5" />}
                </button>
              </div>

              <Button
                type="submit"
                variant="primary"
                loading={loading}
                leftIcon={<LoginIcon className="h-5 w-5" />}
                className="w-full h-12"
              >
                {loading ? t('auth.logging_in') : t('auth.login_button')}
              </Button>
            </form>

            <div className="flex items-center justify-between mt-5 text-sm">
              <Link
                to="/forgot-password"
                className="text-[var(--accent-default)] hover:text-[var(--accent-hover)] font-medium transition-colors"
              >
                {t('auth.forgot_password')}
              </Link>
              <Link
                to="/register"
                className="text-[var(--accent-default)] hover:text-[var(--accent-hover)] font-medium transition-colors"
              >
                {t('registration.title')}
              </Link>
            </div>

            {DevQuickLogin && (
              <React.Suspense fallback={null}>
                <DevQuickLogin
                  disabled={loading}
                  onPick={(loginEmail, loginPassword) => {
                    setEmail(loginEmail);
                    setPassword(loginPassword);
                    void doLogin(loginEmail, loginPassword);
                  }}
                />
              </React.Suspense>
            )}
          </CardContent>
        </Card>

        <div className="text-center mt-8 space-y-1">
          <p className="text-sm text-[var(--text-secondary)]">
            {systemSettings?.name || t('common.app_name')} &copy; {new Date().getFullYear()}
          </p>
          <p className="text-xs text-[var(--text-secondary)]">
            طارق الشهري - مجمع إرادة والصحة النفسية
          </p>
          <p className="text-xs text-[var(--text-secondary)]">
            إدارة التخطيط والتحول ومكتب إدارة المشاريع
          </p>
        </div>
      </main>
    </div>
  );
};

export default IconLogin;
