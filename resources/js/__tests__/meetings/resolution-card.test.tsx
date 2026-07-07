import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test' } }),
}));

vi.mock('@shared/api/access', () => ({
  useCan: () => true,
}));

vi.mock('@features/meetings/resolutions/api', () => ({
  resolutionsApi: {
    start: vi.fn().mockResolvedValue({ resolution: {} }),
    hold: vi.fn().mockResolvedValue({ resolution: {} }),
    releaseHold: vi.fn().mockResolvedValue({ resolution: {} }),
    convertToTasks: vi.fn().mockResolvedValue({ resolution: {}, planned_tasks: [] }),
    complete: vi.fn().mockResolvedValue({ resolution: {} }),
    cancel: vi.fn().mockResolvedValue({ resolution: {} }),
    remove: vi.fn().mockResolvedValue({ message: 'deleted' }),
    update: vi.fn().mockResolvedValue({ resolution: {} }),
  },
}));

vi.mock('@features/meetings/resolutions/ResolutionForm', () => ({
  default: () => null,
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(), showToast: vi.fn() }),
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));

import ResolutionCard from '@features/meetings/resolutions/ResolutionCard';
import type { MeetingResolution } from '@features/meetings/resolutions/types';

const baseResolution: MeetingResolution = {
  id: 1,
  reference_number: 'RES-2026-0001',
  organization_id: 1,
  meeting_id: 1,
  kind: 'recommendation',
  title: 'مخرج للاختبار',
  description: null,
  owner_id: 1,
  status: 'open',
  priority: 'medium',
  due_date: null,
  hold_reason: null,
  hold_until: null,
  hold_by: null,
  hold_at: null,
  created_by: 1,
  completed_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00.000Z',
  updated_at: '2026-07-01T00:00:00.000Z',
  status_label: 'مفتوح',
  owner: { id: 1, name: 'المسؤول' },
  links: [],
};

const allPermissions = {
  canUpdate: true,
  canDelete: true,
  canStart: true,
  canHold: true,
  canReleaseHold: true,
  canConvertToTasks: true,
  canComplete: true,
  canCancel: true,
};

describe('ResolutionCard — no legacy approve/reject/adopt buttons', () => {
  it('does not render any approve, reject, adopt, deliberate, or endorse button', () => {
    const { container } = render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    const html = container.innerHTML;
    expect(html).not.toMatch(/approve|اعتماد/i);
    expect(html).not.toMatch(/reject|رفض/i);
    expect(html).not.toMatch(/adopt/i);
    expect(html).not.toMatch(/deliberate/i);
    expect(html).not.toMatch(/endorse/i);
  });

  it('renders the lifecycle actions for an open recommendation', () => {
    render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    // Expected buttons present (use defaultValue fallback)
    expect(screen.getByText(/بدء|بدء التنفيذ/)).toBeTruthy();
    expect(screen.getByText(/تعليق/)).toBeTruthy();
    expect(screen.getByText(/تحويل إلى مهام/)).toBeTruthy();
    expect(screen.getByText(/إغلاق|إكمال/)).toBeTruthy();
    expect(screen.getByText(/إلغاء/)).toBeTruthy();
  });

  it('shows the on-hold banner when is_on_hold is true', () => {
    const held: MeetingResolution = {
      ...baseResolution,
      is_on_hold: true,
      hold_reason: 'بانتظار معلومات',
      hold_until: '2026-08-01T00:00:00.000Z',
      hold_at: '2026-07-01T00:00:00.000Z',
      hold_by: 2,
    };
    render(<ResolutionCard resolution={held} permissions={allPermissions} />);
    // Banner title via defaultValue fallback
    expect(screen.getByText(/القرار معلّق|مخرج معلّق/i)).toBeTruthy();
    expect(screen.getByText(/بانتظار معلومات/)).toBeTruthy();
    // Release button visible (defaultValue in i18n fallback)
    expect(screen.getByText(/رفع التعليق|فك تعليق|فك التعليق/)).toBeTruthy();
  });

  it('hides lifecycle actions when permissions deny them', () => {
    const noPermissions = {
      canUpdate: false,
      canDelete: false,
      canStart: false,
      canHold: false,
      canReleaseHold: false,
      canConvertToTasks: false,
      canComplete: false,
      canCancel: false,
    };
    render(<ResolutionCard resolution={baseResolution} permissions={noPermissions} />);
    expect(screen.queryByText(/بدء|بدء التنفيذ/)).toBeNull();
    expect(screen.queryByText(/تعليق/)).toBeNull();
    expect(screen.queryByText(/إغلاق|إكمال/)).toBeNull();
    expect(screen.queryByText(/إلغاء/)).toBeNull();
  });

  it('renders a decision kind differently from a recommendation', () => {
    const decision: MeetingResolution = { ...baseResolution, kind: 'decision' };
    const { container: rec } = render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    const { container: dec } = render(<ResolutionCard resolution={decision} permissions={allPermissions} />);
    expect(dec.textContent).toContain('قرار');
    expect(rec.textContent).toContain('توصية');
  });
});

describe('ResolutionCard — Phase 3: convert-to-tasks button + tasks-progress indicator', () => {
  it('shows the convert-to-tasks button when status is open', () => {
    render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    expect(screen.queryByTestId('convert-to-tasks-btn')).toBeTruthy();
  });

  it('HIDES the convert-to-tasks button after status is converted_to_tasks', () => {
    const converted: MeetingResolution = { ...baseResolution, status: 'converted_to_tasks' };
    render(<ResolutionCard resolution={converted} permissions={allPermissions} />);
    expect(screen.queryByTestId('convert-to-tasks-btn')).toBeNull();
  });

  it('shows the tasks-progress indicator when tasks_count > 0', () => {
    const withTasks: MeetingResolution = {
      ...baseResolution,
      status: 'converted_to_tasks',
      tasks_count: 5,
      completed_tasks_count: 2,
      pending_tasks_count: 3,
      completion_percentage: 40,
    };
    render(<ResolutionCard resolution={withTasks} permissions={allPermissions} />);
    expect(screen.getByTestId('tasks-progress')).toBeTruthy();
    expect(screen.getByText(/2 \/ 5/)).toBeTruthy();
    expect(screen.getByText(/40%/)).toBeTruthy();
  });

  it('hides the tasks-progress indicator when tasks_count is 0', () => {
    render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    expect(screen.queryByTestId('tasks-progress')).toBeNull();
  });

  it('opens the ConvertToTasksModal when the button is clicked', () => {
    render(<ResolutionCard resolution={baseResolution} permissions={allPermissions} />);
    fireEvent.click(screen.getByTestId('convert-to-tasks-btn'));
    // Modal title appears (defaultValue: "تحويل المخرج إلى مهام")
    expect(screen.getByText(/تحويل المخرج إلى مهام/)).toBeTruthy();
  });
});