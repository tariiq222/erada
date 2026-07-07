import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

export interface CompletionFixtures {
  generatedAt: string;
  stamp: string;
  projectNewForClosure: number;
  projectImpForClosure: number;
  projectNewForValidation: number;
  projectImpForPercent: number;
  projectOnHold: number;
  projectDraft: number;
  projectCompleted: number;
  projectCancelled: number;
  taskImprovement: number;
  taskPlain: number;
  taskParentWithSubtask: number;
  taskChild: number;
  taskParentPositive: number;
  taskChildPositive: number;
  taskForReview: number;
  projectWithOpenTask: number;
}

/** Reads e2e/.fixtures.json produced by scripts/qa/seed-completion-e2e.php. */
export function loadFixtures(): CompletionFixtures {
  return JSON.parse(readFileSync(join(here, '..', '.fixtures.json'), 'utf-8'));
}

/** Logs in as the seeded super_admin via the real login form. */
export async function login(page: Page): Promise<void> {
  await page.goto('/login');
  await page.waitForSelector('input[type="email"]', { timeout: 15000 });
  await page.fill('input[type="email"]', 'admin@admin.com');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard', { timeout: 15000 });
}

/**
 * Selects an option from the project's custom <Select> dropdown.
 * Clicks the trigger showing `currentLabel`, then the option `optionText`.
 */
export async function selectDropdownOption(page: Page, currentLabel: string, optionText: string): Promise<void> {
  await page.locator(`button:has-text("${currentLabel}")`).first().click();
  await page.waitForSelector(`li[role="option"]:has-text("${optionText}")`, { timeout: 5000 });
  await page.locator(`li[role="option"]:has-text("${optionText}")`).first().click();
}

/**
 * Drives the custom DatePicker portal: opens it via the trigger whose
 * accessible name is `triggerName`, then clicks the "Today" footer button.
 */
export async function pickToday(page: Page, triggerText: string): Promise<void> {
  const trigger = page.locator(`button[aria-haspopup="dialog"]:has-text("${triggerText}")`).first();
  await trigger.scrollIntoViewIfNeeded();
  await trigger.click();
  const portal = page.locator('[data-datepicker-portal]');
  await portal.waitFor({ state: 'visible', timeout: 5000 });
  await portal.getByRole('button', { name: 'اختيار تاريخ اليوم' }).click();
  await portal.waitFor({ state: 'hidden', timeout: 5000 });
}
