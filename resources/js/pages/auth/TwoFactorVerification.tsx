import React, { useState, useRef, useEffect } from 'react';
import { useNavigate, useLocation, Navigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useLocale } from '@shared/contexts/LocaleContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import { twoFactorApi } from '@shared/api/twoFactor';
import { api } from '@shared/api/client';
import { Button, Card, CardContent } from '@shared/ui';
import {IconShield, IconKey, IconArrowRight, IconRefresh} from '@tabler/icons-react';

interface LocationState {
  pendingToken: string;
  userId: number;
  userName?: string;
}

const TWO_FACTOR_ERROR_ID = 'two-factor-error';

const TwoFactorVerification: React.FC = () => {
  const { t } = useTranslation();
  const { isAuthenticated, refreshUser } = useAuth();
  const { direction } = useLocale();
  const { settings: systemSettings } = useSystemSettings();
  const navigate = useNavigate();
  const location = useLocation();
  const state = location.state as LocationState | null;

  const [code, setCode] = useState(['', '', '', '', '', '']);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [useRecoveryCode, setUseRecoveryCode] = useState(false);
  const [recoveryCode, setRecoveryCode] = useState('');

  const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

  // التركيز على الحقل الأول عند التحميل
  useEffect(() => {
    if (!useRecoveryCode) {
      inputRefs.current[0]?.focus();
    }
  }, [useRecoveryCode]);

  // إذا كان المستخدم مسجل دخوله بالفعل
  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  // إذا لم تكن هناك بيانات 2FA
  if (!state?.pendingToken || !state?.userId) {
    return <Navigate to="/login" replace />;
  }

  const handleCodeChange = (index: number, value: string) => {
    // قبول الأرقام فقط
    if (!/^\d*$/.test(value)) return;

    const newCode = [...code];
    newCode[index] = value.slice(-1); // آخر رقم فقط
    setCode(newCode);

    // الانتقال للحقل التالي
    if (value && index < 5) {
      inputRefs.current[index + 1]?.focus();
    }
  };

  const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    // العودة للحقل السابق عند الحذف
    if (e.key === 'Backspace' && !code[index] && index > 0) {
      inputRefs.current[index - 1]?.focus();
    }
  };

  const handlePaste = (e: React.ClipboardEvent) => {
    e.preventDefault();
    const pastedData = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (pastedData.length === 6) {
      const newCode = pastedData.split('');
      setCode(newCode);
      inputRefs.current[5]?.focus();
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    const verificationCode = useRecoveryCode ? recoveryCode : code.join('');

    if (!verificationCode || (useRecoveryCode ? verificationCode.length < 8 : verificationCode.length !== 6)) {
      setError(useRecoveryCode ? t('auth.enter_recovery_code') : t('auth.enter_6_digit_code'));
      setLoading(false);
      return;
    }

    try {
      const response = await twoFactorApi.verify(state.userId, verificationCode, state.pendingToken);

      // حفظ التوكن وتحديث حالة المصادقة
      if (response.token) {
        api.setToken(response.token);
      }
      api.setAuthenticated(true);

      // تحديث بيانات المستخدم
      await refreshUser();

      // الانتقال للوحة التحكم
      navigate('/dashboard', { replace: true });
    } catch (err: any) {
      const errorMessage = err.message || t('auth.invalid_verification_code');
      setError(errorMessage);
      // مسح الكود عند الخطأ
      if (!useRecoveryCode) {
        setCode(['', '', '', '', '', '']);
        inputRefs.current[0]?.focus();
      }
    } finally {
      setLoading(false);
    }
  };

  const toggleRecoveryMode = () => {
    setUseRecoveryCode(!useRecoveryCode);
    setError('');
    setCode(['', '', '', '', '', '']);
    setRecoveryCode('');
  };

  return (
    <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center p-4" dir={direction}>
      <div className="w-full max-w-md">
        {/* Logo */}
        <div className="text-center mb-8">
          <div className="h-20 w-20 rounded-xl bg-[var(--accent-default)] flex items-center justify-center mx-auto mb-6">
            <IconShield aria-hidden="true" className="h-10 w-10 text-[var(--text-inverse)]" />
          </div>
          <h1 className="text-3xl font-bold text-[var(--text-primary)] mb-2">{t('auth.two_factor_title')}</h1>
          <p className="text-[var(--text-tertiary)]">
            {state?.userName ? t('auth.two_factor_welcome', { name: state.userName }) : t('auth.two_factor_enter_code_description')}
          </p>
        </div>

        <Card className="bg-[var(--surface-base)] border-[var(--border-default)]">
          <CardContent className="p-8">
            {!useRecoveryCode ? (
              <>
                <div className="flex items-center justify-center gap-3 mb-6">
                  <IconKey aria-hidden="true" className="h-6 w-6 text-[var(--accent-default)]" />
                  <h2 className="text-lg font-semibold text-[var(--text-primary)]">
                    {t('auth.enter_verification_code')}
                  </h2>
                </div>

                <p className="text-[var(--text-tertiary)] text-sm text-center mb-6">
                  {t('auth.auth_app_instruction')}
                </p>
              </>
            ) : (
              <>
                <div className="flex items-center justify-center gap-3 mb-6">
                  <IconRefresh aria-hidden="true" className="h-6 w-6 text-[var(--accent-default)]" />
                  <h2 className="text-lg font-semibold text-[var(--text-primary)]">
                    {t('auth.recovery_code')}
                  </h2>
                </div>

                <p className="text-[var(--text-tertiary)] text-sm text-center mb-6">
                  {t('auth.recovery_code_instruction')}
                </p>
              </>
            )}

            {error && (
              <div
                id={TWO_FACTOR_ERROR_ID}
                role="alert"
                className="mb-6 p-4 rounded-lg bg-[var(--status-danger-subtle)] border border-[var(--status-danger)]/30"
              >
                <p className="text-[var(--status-danger)] text-sm text-center">{error}</p>
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-6">
              {!useRecoveryCode ? (
                <div
                  className="flex justify-center gap-2"
                  dir="ltr"
                  role="group"
                  aria-label={t('auth.enter_verification_code')}
                  aria-describedby={error ? TWO_FACTOR_ERROR_ID : undefined}
                  aria-invalid={error ? true : undefined}
                  onPaste={handlePaste}
                >
                  {code.map((digit, index) => (
                    <input
                      key={index}
                      ref={(el) => { inputRefs.current[index] = el; }}
                      type="text"
                      inputMode="numeric"
                      maxLength={1}
                      aria-label={t('auth.digit_n', { defaultValue: 'الرقم {{n}}', n: index + 1 })}
                      aria-invalid={error ? true : undefined}
                      value={digit}
                      onChange={(e) => handleCodeChange(index, e.target.value)}
                      onKeyDown={(e) => handleKeyDown(index, e)}
                      className="w-12 h-14 text-center text-2xl font-bold bg-[var(--surface-muted)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:border-[var(--accent-default)] focus:ring-2 focus:ring-[var(--accent-subtle)] transition-colors aria-[invalid=true]:border-[var(--status-danger)]"
                      disabled={loading}
                    />
                  ))}
                </div>
              ) : (
                <>
                  <label htmlFor="two-factor-recovery-code" className="sr-only">
                    {t('auth.recovery_code')}
                  </label>
                  <input
                    id="two-factor-recovery-code"
                    type="text"
                    value={recoveryCode}
                    onChange={(e) => setRecoveryCode(e.target.value.toUpperCase())}
                    placeholder="XXXX-XXXX-XXXX"
                    aria-describedby={error ? TWO_FACTOR_ERROR_ID : undefined}
                    aria-invalid={error ? true : undefined}
                    className="w-full h-14 text-center text-lg font-mono bg-[var(--surface-muted)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] focus:outline-none focus:border-[var(--accent-default)] focus:ring-2 focus:ring-[var(--accent-subtle)] transition-colors tracking-wider"
                    disabled={loading}
                    dir="ltr"
                  />
                </>
              )}

              <Button
                type="submit"
                variant="primary"
                loading={loading}
                leftIcon={<IconArrowRight className="h-5 w-5" />}
                className="w-full h-12"
              >
                {loading ? t('auth.verifying') : t('auth.verify')}
              </Button>
            </form>

            <div className="mt-6 text-center">
              <button
                type="button"
                onClick={toggleRecoveryMode}
                className="inline-flex min-h-11 items-center justify-center px-3 py-2 text-sm text-[var(--accent-default)] hover:text-[var(--accent-hover)] transition-colors"
              >
                {useRecoveryCode ? t('auth.use_verification_code') : t('auth.cant_access_auth_app')}
              </button>
            </div>

            <div className="mt-4 text-center">
              <button
                type="button"
                onClick={() => navigate('/login', { replace: true })}
                className="inline-flex min-h-11 items-center justify-center px-3 py-2 text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors"
              >
                {t('auth.back_to_login')}
              </button>
            </div>
          </CardContent>
        </Card>

        <p className="text-center text-sm text-[var(--text-tertiary)] mt-8">
          {systemSettings?.name || t('common.app_name')} &copy; {new Date().getFullYear()}
        </p>
      </div>
    </div>
  );
};

export default TwoFactorVerification;
