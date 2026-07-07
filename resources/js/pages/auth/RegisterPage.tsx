import React, { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import { Card, CardContent, Input, Button, useToast } from '@shared/ui';
import LanguageSwitcher from '@shared/ui/LanguageSwitcher';
import ThemeSwitcher from '@shared/ui/ThemeSwitcher';
import {
  IconMail,
  IconLock,
  IconUser,
  IconLoader,
  IconCheck,
  IconPhone,
  IconBriefcase,
  IconBuilding,
  IconHash,
  IconEye,
  IconEyeOff,
} from '@tabler/icons-react';
import { registrationApi } from '@features/auth/registrationApi';

const RegisterPage: React.FC = () => {
  const { t } = useTranslation();
  const { isAuthenticated, isLoading: authLoading, refreshUser } = useAuth();
  const { direction } = useLocale();
  const { settings: systemSettings } = useSystemSettings();
  const navigate = useNavigate();
  const { showToast } = useToast();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [jobTitle, setJobTitle] = useState('');
  const [phone, setPhone] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  if (authLoading) {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center">
        <div className="text-center">
          <h1 className="sr-only">{t('registration.title')}</h1>
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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      await registrationApi.register({
        name: name.trim(),
        email: email.trim(),
        password,
        password_confirmation: passwordConfirmation,
        department_id: departmentId ? Number(departmentId) : null,
        job_title: jobTitle.trim() || null,
        phone: phone.trim() || null,
        organization_id: organizationId ? Number(organizationId) : null,
      });
      await refreshUser();
      showToast('success', t('registration.register_success'));
      navigate('/dashboard');
    } catch (err) {
      const message =
        (err as { message?: string })?.message ||
        t('registration.register_failed');
      setError(message);
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
            {t('registration.title')}
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
                <p className="text-[var(--status-danger-text)] text-sm text-center">
                  {error}
                </p>
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-5">
              <Input
                id="register-name"
                name="name"
                type="text"
                autoComplete="name"
                label="الاسم الكامل"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('registration.name_placeholder')}
                leftIcon={<IconUser className="h-5 w-5" />}
                required
                autoFocus
              />

              <Input
                id="register-email"
                name="email"
                type="email"
                autoComplete="email"
                label="البريد الإلكتروني"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="example@domain.com"
                leftIcon={<IconMail className="h-5 w-5" />}
                required
              />

              <div className="relative">
                <Input
                  id="register-password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  label="كلمة المرور"
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

              <div className="relative">
                <Input
                  id="register-password-confirmation"
                  name="password_confirmation"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  label="تأكيد كلمة المرور"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  placeholder="••••••••"
                  leftIcon={<IconLock className="h-5 w-5" />}
                  className="pe-10"
                  required
                />
              </div>

              <Input
                id="register-department-id"
                name="department_id"
                type="number"
                label="رقم القسم (اختياري)"
                value={departmentId}
                onChange={(e) => setDepartmentId(e.target.value)}
                placeholder="—"
                leftIcon={<IconHash className="h-5 w-5" />}
                dir="ltr"
                min={0}
              />

              <Input
                id="register-job-title"
                name="job_title"
                type="text"
                autoComplete="organization-title"
                label="المسمى الوظيفي (اختياري)"
                value={jobTitle}
                onChange={(e) => setJobTitle(e.target.value)}
                placeholder="مثال: مهندس برمجيات"
                leftIcon={<IconBriefcase className="h-5 w-5" />}
              />

              <Input
                id="register-phone"
                name="phone"
                type="tel"
                autoComplete="tel"
                label="رقم الجوال (اختياري)"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="05XXXXXXXX"
                leftIcon={<IconPhone className="h-5 w-5" />}
                dir="ltr"
              />

              <Input
                id="register-organization-id"
                name="organization_id"
                type="number"
                label="رقم المنظمة (اختياري)"
                value={organizationId}
                onChange={(e) => setOrganizationId(e.target.value)}
                placeholder="—"
                leftIcon={<IconBuilding className="h-5 w-5" />}
                dir="ltr"
                min={0}
              />

              <Button
                type="submit"
                variant="primary"
                loading={loading}
                leftIcon={<IconCheck className="h-5 w-5" />}
                className="w-full h-12"
              >
                {loading ? t('common.loading') : t('registration.register_button')}
              </Button>

              <p className="text-center text-sm text-[var(--text-tertiary)] pt-2">
                {t('registration.already_have_account')}{' '}
                <Link
                  to="/login"
                  className="text-[var(--accent-default)] hover:text-[var(--accent-hover)] font-medium"
                >
                  {t('auth.login')}
                </Link>
              </p>
            </form>
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

export default RegisterPage;