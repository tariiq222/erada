/**
 * Two-Factor Authentication (2FA) APIs - المصادقة الثنائية
 */

import { api } from '@shared/api/client';

// ============ Types ============

export interface TwoFactorStatus {
  enabled: boolean;
  confirmed: boolean;
  required: boolean;
  has_recovery_codes: boolean;
}

export interface TwoFactorEnableResponse {
  message: string;
  qr_code: string;          // Base64 QR code image
  secret: string;           // Manual entry secret
  recovery_codes: string[]; // Backup recovery codes
}

export interface TwoFactorConfirmResponse {
  message: string;
  recovery_codes: string[];
}

export interface TwoFactorVerifyResponse {
  message: string;
  user: {
    id: number;
    name: string;
    email: string;
    [key: string]: unknown;
  };
  token: string;
}

export interface TwoFactorRecoveryCodesResponse {
  message: string;
  recovery_codes: string[];
}

// ============ API ============

export const twoFactorApi = {
  /**
   * الحصول على حالة 2FA للمستخدم الحالي
   */
  status: () => api.get<TwoFactorStatus>('/2fa/status'),

  /**
   * بدء تفعيل 2FA
   * يُرجع QR code وأكواد الاسترداد
   */
  enable: (password: string) =>
    api.post<TwoFactorEnableResponse>('/2fa/enable', { password }),

  /**
   * تأكيد تفعيل 2FA بإدخال الكود من تطبيق Authenticator
   */
  confirm: (code: string) =>
    api.post<TwoFactorConfirmResponse>('/2fa/confirm', { code }),

  /**
   * تعطيل 2FA
   */
  disable: (password: string, code: string) =>
    api.post<{ message: string }>('/2fa/disable', { password, code }),

  /**
   * إعادة توليد أكواد الاسترداد
   */
  regenerateRecoveryCodes: (password: string) =>
    api.post<TwoFactorRecoveryCodesResponse>('/2fa/recovery-codes', { password }),

  /**
   * التحقق من كود 2FA أثناء تسجيل الدخول
   * يُستخدم بعد إدخال بيانات الدخول إذا كان 2FA مفعلاً
   */
  verify: (userId: number, code: string, pendingToken: string) =>
    api.post<TwoFactorVerifyResponse>('/2fa/verify', {
      user_id: userId,
      code,
      pending_token: pendingToken,
    }),
};
