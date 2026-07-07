/**
 * Registration & Password Reset API (public endpoints, no auth)
 *
 *   POST /register          - direct self-registration (single-step, no invite, no OTP, no admin approval)
 *   POST /password/forgot   - send OTP for password reset
 *   POST /password/reset    - consume OTP + set new password
 *
 * The previous admin approval queue (GET /registrations, approve/reject,
 * bulk-approve) and the roster import (POST /roster/import) endpoints were
 * removed in the simplified-registration cutover. Privilege elevation no
 * longer happens through a public API — it goes through the admin
 * RoleController UI (out of scope here).
 */

import { api } from '@shared/api/client';
import type { User } from '@shared/types';

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  department_id?: number | null;
  job_title?: string | null;
  phone?: string | null;
  organization_id?: number | null;
}

export interface PasswordResetPayload {
  email: string;
  code: string;
  password: string;
  password_confirmation: string;
}

interface ApiMessageResponse {
  message: string;
}

export const registrationApi = {
  // --- Public self-registration (single-step) ---
  register: (payload: RegisterPayload) =>
    api.post<{ user: User; message: string }>('/register', payload),

  // --- Public password reset flow ---
  forgot: (email: string) =>
    api.post<ApiMessageResponse>('/password/forgot', { email }),

  reset: (payload: PasswordResetPayload) =>
    api.post<ApiMessageResponse>('/password/reset', payload),
};
