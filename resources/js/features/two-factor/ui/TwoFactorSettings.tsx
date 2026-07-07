import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconShield, IconShieldCheck, IconShieldOff, IconDeviceMobile, IconCopy, IconCircleCheck, IconLoader, IconAlertTriangle, IconRefresh, IconEye, IconEyeOff} from '@tabler/icons-react';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Badge } from '@shared/ui/Badge';
import { useToast } from '@shared/ui/Toast';
import { twoFactorApi, type TwoFactorStatus } from '@shared/api/twoFactor';

type Step = 'status' | 'enable' | 'confirm' | 'recovery' | 'disable';

export const TwoFactorSettings: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [status, setStatus] = useState<TwoFactorStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [step, setStep] = useState<Step>('status');
  const [actionLoading, setActionLoading] = useState(false);

  // Enable flow
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [qrCode, setQrCode] = useState('');
  const [secret, setSecret] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [confirmCode, setConfirmCode] = useState('');

  // Disable flow
  const [disablePassword, setDisablePassword] = useState('');
  const [disableCode, setDisableCode] = useState('');

  const [copiedSecret, setCopiedSecret] = useState(false);
  const [copiedCodes, setCopiedCodes] = useState(false);

  useEffect(() => {
    loadStatus();
  }, []);

  const loadStatus = async () => {
    try {
      const data = await twoFactorApi.status();
      setStatus(data);
    } catch (error: any) {
      showToast('error', t('profile.2fa.failed_to_load_status'));
    } finally {
      setLoading(false);
    }
  };

  const handleEnable = async () => {
    if (!password) {
      showToast('error', t('profile.2fa.please_enter_password'));
      return;
    }

    setActionLoading(true);
    try {
      const response = await twoFactorApi.enable(password);
      setQrCode(response.qr_code);
      setSecret(response.secret);
      setRecoveryCodes(response.recovery_codes);
      setStep('confirm');
      setPassword('');
    } catch (error: any) {
      showToast('error', error.message || t('profile.2fa.failed_to_enable'));
    } finally {
      setActionLoading(false);
    }
  };

  const handleConfirm = async () => {
    if (!confirmCode || confirmCode.length !== 6) {
      showToast('error', t('profile.2fa.please_enter_6_digit_code'));
      return;
    }

    setActionLoading(true);
    try {
      const response = await twoFactorApi.confirm(confirmCode);
      setRecoveryCodes(response.recovery_codes);
      setStep('recovery');
      setConfirmCode('');
      await loadStatus();
      showToast('success', t('profile.2fa.enabled_successfully'));
    } catch (error: any) {
      showToast('error', error.message || t('profile.2fa.invalid_verification_code'));
    } finally {
      setActionLoading(false);
    }
  };

  const handleDisable = async () => {
    if (!disablePassword || !disableCode) {
      showToast('error', t('profile.2fa.please_enter_password_and_code'));
      return;
    }

    setActionLoading(true);
    try {
      await twoFactorApi.disable(disablePassword, disableCode);
      setStep('status');
      setDisablePassword('');
      setDisableCode('');
      await loadStatus();
      showToast('success', t('profile.2fa.disabled_successfully'));
    } catch (error: any) {
      showToast('error', error.message || t('profile.2fa.failed_to_disable'));
    } finally {
      setActionLoading(false);
    }
  };

  const handleRegenerateRecoveryCodes = async () => {
    if (!password) {
      showToast('error', t('profile.2fa.please_enter_password'));
      return;
    }

    setActionLoading(true);
    try {
      const response = await twoFactorApi.regenerateRecoveryCodes(password);
      setRecoveryCodes(response.recovery_codes);
      setStep('recovery');
      setPassword('');
      showToast('success', t('profile.2fa.recovery_codes_regenerated'));
    } catch (error: any) {
      showToast('error', error.message || t('profile.2fa.failed_to_regenerate_codes'));
    } finally {
      setActionLoading(false);
    }
  };

  const copyToClipboard = async (text: string, type: 'secret' | 'codes') => {
    try {
      await navigator.clipboard.writeText(text);
      if (type === 'secret') {
        setCopiedSecret(true);
        setTimeout(() => setCopiedSecret(false), 2000);
      } else {
        setCopiedCodes(true);
        setTimeout(() => setCopiedCodes(false), 2000);
      }
      showToast('success', t('common.copied'));
    } catch {
      showToast('error', t('common.copy_failed'));
    }
  };

  const resetFlow = () => {
    setStep('status');
    setPassword('');
    setQrCode('');
    setSecret('');
    setRecoveryCodes([]);
    setConfirmCode('');
    setDisablePassword('');
    setDisableCode('');
  };

  if (loading) {
    return (
      <Card>
        <CardContent className="p-6">
          <div className="flex items-center justify-center py-8">
            <IconLoader className="h-8 w-8 animate-spin text-[var(--accent-default)]" />
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <IconShield className="h-5 w-5" />
          {t('profile.2fa.title')}
          {status?.enabled && status?.confirmed && (
            <Badge variant="success" className="mr-2">{t('profile.2fa.enabled')}</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-6">
        {/* Status View */}
        {step === 'status' && (
          <div className="space-y-6">
            <div className="flex items-start gap-4 p-4 rounded-lg bg-[var(--surface-subtle)] dark:bg-[var(--surface-muted)]">
              {status?.enabled && status?.confirmed ? (
                <>
                  <IconShieldCheck className="h-10 w-10 text-[var(--status-success)] shrink-0" />
                  <div>
                    <h3 className="font-semibold text-[var(--text-primary)] dark:text-[var(--text-primary)]">
                      {t('profile.2fa.status_enabled')}
                    </h3>
                    <p className="text-sm text-[var(--text-secondary)] dark:text-[var(--text-tertiary)] mt-1">
                      {t('profile.2fa.status_enabled_description')}
                    </p>
                  </div>
                </>
              ) : (
                <>
                  <IconShieldOff className="h-10 w-10 text-[var(--status-warning)] shrink-0" />
                  <div>
                    <h3 className="font-semibold text-[var(--text-primary)] dark:text-[var(--text-primary)]">
                      {t('profile.2fa.status_disabled')}
                    </h3>
                    <p className="text-sm text-[var(--text-secondary)] dark:text-[var(--text-tertiary)] mt-1">
                      {t('profile.2fa.status_disabled_description')}
                    </p>
                  </div>
                </>
              )}
            </div>

            <div className="flex flex-wrap gap-3">
              {status?.enabled && status?.confirmed ? (
                <>
                  <Button
                    variant="outline"
                    onClick={() => setStep('enable')}
                    leftIcon={<IconRefresh className="h-4 w-4" />}
                  >
                    {t('profile.2fa.regenerate_recovery_codes')}
                  </Button>
                  <Button
                    variant="danger"
                    onClick={() => setStep('disable')}
                    leftIcon={<IconShieldOff className="h-4 w-4" />}
                  >
                    {t('profile.2fa.disable_2fa')}
                  </Button>
                </>
              ) : (
                <Button onClick={() => setStep('enable')} leftIcon={<IconDeviceMobile className="h-4 w-4" />}>
                  {t('profile.2fa.enable_2fa')}
                </Button>
              )}
            </div>
          </div>
        )}

        {/* Enable Flow - Step 1: Enter Password */}
        {step === 'enable' && !qrCode && (
          <div className="space-y-6">
            <div className="flex items-center gap-3 pb-4 border-b">
              <IconDeviceMobile className="h-6 w-6 text-[var(--accent-default)]" />
              <div>
                <h3 className="font-semibold text-[var(--text-primary)] dark:text-[var(--text-primary)]">
                  {status?.enabled ? t('profile.2fa.regenerate_recovery_codes') : t('profile.2fa.enable_2fa')}
                </h3>
                <p className="text-sm text-[var(--text-tertiary)]">{t('profile.2fa.enter_password_to_continue')}</p>
              </div>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)] mb-1">
                  {t('auth.password')}
                </label>
                <div className="relative">
                  <Input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder={t('profile.2fa.enter_password')}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]"
                  >
                    {showPassword ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
                  </button>
                </div>
              </div>
            </div>

            <div className="flex gap-3 pt-4">
              <Button variant="outline" onClick={resetFlow}>
                {t('common.cancel')}
              </Button>
              <Button
                onClick={status?.enabled ? handleRegenerateRecoveryCodes : handleEnable}
                loading={actionLoading}
              >
                {t('common.continue')}
              </Button>
            </div>
          </div>
        )}

        {/* Enable Flow - Step 2: Scan QR Code */}
        {step === 'confirm' && qrCode && (
          <div className="space-y-6">
            <div className="flex items-center gap-3 pb-4 border-b">
              <IconDeviceMobile className="h-6 w-6 text-[var(--accent-default)]" />
              <div>
                <h3 className="font-semibold text-[var(--text-primary)] dark:text-[var(--text-primary)]">
                  {t('profile.2fa.scan_qr_code')}
                </h3>
                <p className="text-sm text-[var(--text-tertiary)]">{t('profile.2fa.scan_qr_code_description')}</p>
              </div>
            </div>

            <div className="flex flex-col items-center space-y-4">
              <div className="p-4 bg-white rounded-lg border">
                <img src={qrCode} alt="QR Code" className="w-48 h-48" />
              </div>

              <div className="w-full max-w-md">
                <p className="text-sm text-[var(--text-secondary)] dark:text-[var(--text-tertiary)] text-center mb-2">
                  {t('profile.2fa.or_enter_manually')}
                </p>
                <div className="flex items-center gap-2 p-3 bg-[var(--surface-muted)] dark:bg-[var(--surface-muted)] rounded-lg font-mono text-sm">
                  <span className="flex-1 text-center break-all">{secret}</span>
                  <button
                    onClick={() => copyToClipboard(secret, 'secret')}
                    className="p-1 hover:bg-[var(--surface-muted)] dark:hover:bg-[var(--surface-muted)] rounded"
                  >
                    {copiedSecret ? (
                      <IconCircleCheck className="h-4 w-4 text-[var(--status-success)]" />
                    ) : (
                      <IconCopy className="h-4 w-4 text-[var(--text-tertiary)]" />
                    )}
                  </button>
                </div>
              </div>
            </div>

            <div className="space-y-4 pt-4 border-t">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)] mb-1">
                  {t('profile.2fa.verification_code_from_app')}
                </label>
                <Input
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  value={confirmCode}
                  onChange={(e) => setConfirmCode(e.target.value.replace(/\D/g, ''))}
                  placeholder={t('profile.2fa.enter_6_digit_code')}
                  className="text-center text-lg tracking-widest"
                  dir="ltr"
                />
              </div>
            </div>

            <div className="flex gap-3">
              <Button variant="outline" onClick={resetFlow}>
                {t('common.cancel')}
              </Button>
              <Button onClick={handleConfirm} loading={actionLoading}>
                {t('profile.2fa.confirm_activation')}
              </Button>
            </div>
          </div>
        )}

        {/* Recovery Codes Display */}
        {step === 'recovery' && recoveryCodes.length > 0 && (
          <div className="space-y-6">
            <div className="flex items-start gap-3 p-4 rounded-lg bg-[var(--status-warning-subtle)] dark:bg-[var(--status-warning-subtle)] border border-[var(--status-warning-subtle)] dark:border-[var(--status-warning)]/30">
              <IconAlertTriangle className="h-5 w-5 text-[var(--status-warning)] shrink-0 mt-0" />
              <div>
                <h4 className="font-semibold text-[var(--status-warning-text)] dark:text-[var(--status-warning-text)]">
                  {t('profile.2fa.save_recovery_codes')}
                </h4>
                <p className="text-sm text-[var(--status-warning-text)] dark:text-[var(--status-warning-text)] mt-1">
                  {t('profile.2fa.recovery_codes_description')}
                </p>
              </div>
            </div>

            <div className="p-4 bg-[var(--surface-subtle)] dark:bg-[var(--surface-muted)] rounded-lg">
              <div className="flex justify-between items-center mb-3">
                <span className="text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)]">
                  {t('profile.2fa.recovery_codes')}
                </span>
                <button
                  onClick={() => copyToClipboard(recoveryCodes.join('\n'), 'codes')}
                  className="flex items-center gap-1 text-sm text-[var(--accent-default)] hover:text-[var(--accent-default)]"
                >
                  {copiedCodes ? (
                    <>
                      <IconCircleCheck className="h-4 w-4" />
                      {t('common.copied')}
                    </>
                  ) : (
                    <>
                      <IconCopy className="h-4 w-4" />
                      {t('common.copy_all')}
                    </>
                  )}
                </button>
              </div>
              <div className="grid grid-cols-2 gap-2">
                {recoveryCodes.map((code, index) => (
                  <div
                    key={index}
                    className="p-2 bg-white dark:bg-[var(--surface-subtle)] rounded border font-mono text-sm text-center"
                  >
                    {code}
                  </div>
                ))}
              </div>
            </div>

            <div className="flex justify-end">
              <Button onClick={resetFlow} leftIcon={<IconCircleCheck className="h-4 w-4" />}>
                {t('profile.2fa.saved')}
              </Button>
            </div>
          </div>
        )}

        {/* Disable Flow */}
        {step === 'disable' && (
          <div className="space-y-6">
            <div className="flex items-start gap-3 p-4 rounded-lg bg-[var(--status-danger-subtle)] dark:bg-[var(--status-danger-subtle)] border border-[var(--status-danger-subtle)] dark:border-[var(--status-danger)]/30">
              <IconAlertTriangle className="h-5 w-5 text-[var(--status-danger)] shrink-0 mt-0" />
              <div>
                <h4 className="font-semibold text-[var(--status-danger-text)] dark:text-[var(--status-danger-text)]">
                  {t('profile.2fa.disable_warning_title')}
                </h4>
                <p className="text-sm text-[var(--status-danger-text)] dark:text-[var(--status-danger-text)] mt-1">
                  {t('profile.2fa.disable_warning_description')}
                </p>
              </div>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)] mb-1">
                  {t('auth.password')}
                </label>
                <Input
                  type="password"
                  value={disablePassword}
                  onChange={(e) => setDisablePassword(e.target.value)}
                  placeholder={t('profile.2fa.enter_password')}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)] mb-1">
                  {t('profile.2fa.verification_code_from_app')}
                </label>
                <Input
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  value={disableCode}
                  onChange={(e) => setDisableCode(e.target.value.replace(/\D/g, ''))}
                  placeholder={t('profile.2fa.enter_6_digit_code')}
                  className="text-center tracking-widest"
                  dir="ltr"
                />
              </div>
            </div>

            <div className="flex gap-3">
              <Button variant="outline" onClick={resetFlow}>
                {t('common.cancel')}
              </Button>
              <Button variant="danger" onClick={handleDisable} loading={actionLoading}>
                {t('profile.2fa.disable_2fa')}
              </Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default TwoFactorSettings;
