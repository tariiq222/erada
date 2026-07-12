import { expect, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';

export const adminFixture = {
  adminEmail: 'admin-e2e@example.test',
  twoFactorEmail: 'admin-2fa-e2e@example.test',
  regularEmail: 'regular-e2e@example.test',
  password: 'AdminE2E!Password123',
} as const;

export const ADMIN_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

export async function login(page: Page, email: string = adminFixture.adminEmail, returnTo: string = '/overview'): Promise<void> {
  await page.goto(`/login?returnTo=${encodeURIComponent(returnTo)}`);
  await page.locator('#admin-email').fill(email);
  await page.locator('#admin-password').fill(adminFixture.password);
  await page.getByRole('button', { name: /login|دخول/i }).click();
}

export async function loginAsSuperAdmin(page: Page, returnTo: string = '/overview'): Promise<void> {
  await login(page, adminFixture.adminEmail, returnTo);
  const safeReturn = returnTo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  await expect(page).toHaveURL(new RegExp(`${safeReturn}$`));
  await expect(page.getByTestId('admin-control-plane-shell')).toBeVisible();
}

export async function loginAsTwoFactorUser(page: Page, returnTo: string = '/security/alerts'): Promise<void> {
  await login(page, adminFixture.twoFactorEmail, returnTo);
  await expect(page).toHaveURL(/\/verify-2fa$/);
  await expect(page.locator('input[autocomplete="one-time-code"]')).toHaveCount(6);
}

export async function completeTwoFactorChallenge(page: Page): Promise<void> {
  const code = generateTotp(ADMIN_TWO_FACTOR_SECRET);
  const digits = code.split('');
  const inputs = page.locator('input[autocomplete="one-time-code"]');
  for (let index = 0; index < 6; index += 1) {
    await inputs.nth(index).fill(digits[index] ?? '');
  }
  await page.getByRole('button', { name: /verify|تحقق/i }).click();
}

export async function loginAsTwoFactorSuperAdmin(page: Page, returnTo: string = '/overview'): Promise<void> {
  await loginAsTwoFactorUser(page, returnTo);
  await completeTwoFactorChallenge(page);
  await expect(page).toHaveURL(/\/overview$/);
  await expect(page.getByTestId('admin-control-plane-shell')).toBeVisible();
}

/**
 * RFC 6238 TOTP code generator (HMAC-SHA1, 30s step, 6 digits). Laravel's
 * `TwoFactorService::generateTotp()` uses the same algorithm against the
 * encrypted secret stored on the User row. The fixture seeder encrypts the
 * exact secret `JBSWY3DPEHPK3PXP` (the canonical "Hello!" Base32 sample)
 * so this helper yields the same code Google Authenticator would show.
 */
export function generateTotp(base32Secret: string, atSeconds: number = Math.floor(Date.now() / 1000)): string {
  const key = base32Decode(base32Secret);
  const counter = Math.floor(atSeconds / 30);
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(BigInt(counter));
  const hmac = createHmac('sha1', key).update(buffer).digest();
  const offset = hmac[19] & 0x0f;
  const binary = ((hmac[offset]! & 0x7f) << 24)
    | ((hmac[offset + 1]! & 0xff) << 16)
    | ((hmac[offset + 2]! & 0xff) << 8)
    | (hmac[offset + 3]! & 0xff);
  const code = (binary % 1_000_000).toString().padStart(6, '0');
  return code;
}

function base32Decode(data: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const cleaned = data.toUpperCase().replace(/=+$/, '');
  let buffer = 0;
  let bits = 0;
  const result: number[] = [];
  for (const char of cleaned) {
    const value = alphabet.indexOf(char);
    if (value < 0) continue;
    buffer = (buffer << 5) | value;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      result.push((buffer >> bits) & 0xff);
    }
  }
  return Buffer.from(result);
}

/**
 * Build a unique suffix for ephemeral rows so concurrent or retried runs
 * never collide on a unique-constrained code.
 */
export function uniqueSuffix(label: string): string {
  const stamp = Date.now().toString(36);
  const worker = process.env.TEST_PARALLEL_INDEX ?? process.env.PWTEST_WORKER_INDEX ?? '0';
  const random = Math.random().toString(36).slice(2, 8);
  return `${label}-${stamp}-${worker}-${random}`.toUpperCase().slice(0, 48);
}
