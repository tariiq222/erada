import React, { useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import { Card, CardContent, Input, Button } from '@shared/ui';
import LanguageSwitcher from '@shared/ui/LanguageSwitcher';
import ThemeSwitcher from '@shared/ui/ThemeSwitcher';
import { useToast } from '@shared/ui/Toast';
import {
  IconMail,
  IconKey,
  IconLock,
  IconLoader,
  IconArrowRight,
  IconArrowLeft,
  IconCheck,
  IconShieldCheck,
} from '@tabler/icons-react';
import { registrationApi } from '@features/auth/registrationApi';

type Step = 'email' | 'reset' | 'done';

const ForgotPasswordPage: React.FC = () => {
  const { t } = useTranslation();
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { direction } = useLocale();
  const { settings: systemSettings } = useSystemSettings();
  const { showToast } = useToast();

  const [step, setStep] = useState<Step>('email');
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const ForwardArrow = direction === 'rtl' ? IconArrowLeft : IconArrowRight;

  if (authLoading) {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center">
        <div className="text-center">
          <h1 className="sr-only">{t('forgot.title')}</h1>
          <img
            src="/images/logo.png"
            alt={t('common.app_name')}
            className="h-20 w-20 mx-auto mb-4"
          />
          <IconLoader className="h-6 w-6 text-[var(--accent-default)] animate-spin mx-auto mb-3" />
          <p className="text-[var(--text-secondary)] font-medium">
            {t('common.loading')}
          </p>
        </div>
      </div>
    );
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const resetError = () => setError('');

  const handleSendCode = async (e: React.FormEvent) => {
    e.preventDefault();
    resetError();
    setLoading(true);
    try {
      // Neutral response — same message whether the email exists or not.
      await registrationApi.forgot(email);
      showToast('success', t('forgot.code_sent'));
      setStep('reset');
    } catch (err) {
      const message =
        (err as { message?: string })?.message || t('forgot.send_failed');
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async () => {
    setLoading(true);
    try {
      await registrationApi.forgot(email);
      showToast('success', t('forgot.code_sent'));
    } catch (err) {
      const message =
        (err as { message?: string })?.message || t('forgot.send_failed');
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  const handleReset = async (e: React.FormEvent) => {
    e.preventDefault();
    resetError();
    if (code.length !== 6) {
      setError(t('registration.code_six_digits'));
      return;
    }
    if (password.length < 8) {
      setError(t('auth.password_min_length'));
      return;
    }
    if (password !== passwordConfirmation) {
      setError(t('registration.password_mismatch'));
      return;
    }
    setLoading(true);
    try {
      await registrationApi.reset({
        email,
        code,
        password,
        password_confirmation: passwordConfirmation,
      });
      setStep('done');
    } catch (err) {
      const message =
        (err as { message?: string })?.message || t('forgot.reset_failed');
      setError(message);
      setCode('');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center p-4"
      dir={direction}
    >
      <div className="absolute top-4 start-4 z-20 flex items-center gap-2">
        <ThemeSwitcher />
        <LanguageSwitcher />
      </div>

      <main className="w-full max-w-md">
        <div className="text-center mb-8">
          <img
            src="/images/logo.png"
            alt={t('common.app_name')}
            className="h-24 w-24 mx-auto mb-6"
          />
          <h1 className="text-3xl font-bold text-[var(--text-primary)] mb-2">
            {systemSettings?.name || t('common.app_name')}
          </h1>
          <p className="text-[var(--text-secondary)] font-medium">
            {t('forgot.title')}
          </p>
        </div>

        <Card className="bg-[var(--surface-base)] border-[var(--border-default)] shadow-md">
          <CardContent className="p-8">
            {error && (
              <div
                role="alert"
                aria-live="polite"
                className="mb-6 p-4 rounded-lg bg-[var(--status-danger-subtle)] border border-[var(--status-danger)]/30"
              >
                <p className="text-[var(--status-danger)] text-sm text-center">
                  {error}
                </p>
              </div>
            )}

            {step === 'email' && (
              <form onSubmit={handleSendCode} className="space-y-5">
                <div className="text-center mb-2">
                  <h2 className="text-xl font-bold text-[var(--text-primary)] mb-1">
                    {t('forgot.step_email_title')}
                  </h2>
                  <p className="text-sm text-[var(--text-tertiary)]">
                    {t('forgot.step_email_subtitle')}
                  </p>
                </div>
                <Input
                  id="forgot-email"
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
                <Button
                  type="submit"
                  variant="primary"
                  loading={loading}
                  leftIcon={<ForwardArrow className="h-5 w-5" />}
                  className="w-full h-12"
                >
                  {loading
                    ? t('common.loading')
                    : t('forgot.step_email_button')}
                </Button>
                <p className="text-center text-sm text-[var(--text-tertiary)] pt-2">
                  <Link
                    to="/login"
                    className="text-[var(--accent-default)] hover:text-[var(--accent-hover)] font-medium"
                  >
                    {t('forgot.back_to_login')}
                  </Link>
                </p>
              </form>
            )}

            {step === 'reset' && (
              <form onSubmit={handleReset} className="space-y-5">
                <div className="text-center mb-2">
                  <h2 className="text-xl font-bold text-[var(--text-primary)] mb-1">
                    {t('forgot.step_reset_title')}
                  </h2>
                  <p className="text-sm text-[var(--text-tertiary)]">
                    {t('forgot.step_reset_subtitle', { email })}
                  </p>
                </div>
                <Input
                  id="forgot-code"
                  name="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  label={t('forgot.step_reset_code_label')}
                  value={code}
                  onChange={(e) =>
                    setCode(e.target.value.replace(/\D/g, '').slice(0, 6))
                  }
                  placeholder="000000"
                  leftIcon={<IconKey className="h-5 w-5" />}
                  required
                  autoFocus
                  dir="ltr"
                />
                <Input
                  id="forgot-password"
                  name="password"
                  type="password"
                  autoComplete="new-password"
                  label={t('auth.new_password')}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  leftIcon={<IconLock className="h-5 w-5" />}
                  required
                />
                <Input
                  id="forgot-password-confirmation"
                  name="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  label={t('auth.confirm_password')}
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  placeholder="••••••••"
                  leftIcon={<IconLock className="h-5 w-5" />}
                  required
                />
                <Button
                  type="submit"
                  variant="primary"
                  loading={loading}
                  leftIcon={<ForwardArrow className="h-5 w-5" />}
                  className="w-full h-12"
                >
                  {loading
                    ? t('common.loading')
                    : t('forgot.step_reset_button')}
                </Button>
                <div className="flex items-center justify-between text-sm pt-2">
                  <button
                    type="button"
                    onClick={() => {
                      setStep('email');
                      setCode('');
                      setPassword('');
                      setPasswordConfirmation('');
                      resetError();
                    }}
                    className="text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors"
                  >
                    {t('registration.change_email')}
                  </button>
                  <button
                    type="button"
                    onClick={handleResend}
                    disabled={loading}
                    className="text-[var(--accent-default)] hover:text-[var(--accent-hover)] font-medium transition-colors"
                  >
                    {t('registration.resend_code')}
                  </button>
                </div>
              </form>
            )}

            {step === 'done' && (
              <div className="text-center space-y-5">
                <div className="mx-auto h-16 w-16 rounded-full bg-[var(--status-success-subtle)] flex items-center justify-center">
                  <IconShieldCheck
                    aria-hidden="true"
                    className="h-9 w-9 text-[var(--status-success)]"
                  />
                </div>
                <h2 className="text-xl font-bold text-[var(--text-primary)]">
                  {t('forgot.reset_success_title')}
                </h2>
                <p className="text-[var(--text-secondary)] text-sm leading-relaxed">
                  {t('forgot.reset_success_body')}
                </p>
                <Link to="/login">
                  <Button
                    type="button"
                    variant="primary"
                    leftIcon={<IconCheck className="h-5 w-5" />}
                    className="w-full h-12"
                  >
                    {t('forgot.go_to_login')}
                  </Button>
                </Link>
              </div>
            )}
          </CardContent>
        </Card>

        <div className="text-center mt-8 space-y-1">
          <p className="text-sm text-[var(--text-secondary)]">
            {systemSettings?.name || t('common.app_name')} &copy;{' '}
            {new Date().getFullYear()}
          </p>
        </div>
      </main>
    </div>
  );
};

export default ForgotPasswordPage;
