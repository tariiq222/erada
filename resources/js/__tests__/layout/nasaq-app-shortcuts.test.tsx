import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { App, type NavGroup } from '@shared/nasaq/app';

const navGroups: NavGroup[] = [
  { id: 'main', items: [{ key: 'dashboard', label: 'لوحة التحكم', icon: 'grid', path: '/dashboard' }] },
];

const baseProps = {
  navGroups,
  activePath: '/dashboard',
  activeHref: '/dashboard',
  onNavigate: vi.fn(),
  sidebarLabels: {
    brandName: 'إرادة',
    brandSub: 'منصة إرادة',
    search: 'بحث',
    sectionOps: 'العمليات',
    sectionAdmin: 'الإدارة',
  },
  topbarLabels: {
    brandSub: 'منصة إرادة',
    search: 'بحث',
    settings: 'الإعدادات',
    fontSize: 'حجم الخط',
    language: 'اللغة',
    appearance: 'المظهر',
    sizeS: 'صغير',
    sizeM: 'متوسط',
    sizeL: 'كبير',
    light: 'فاتح',
    dark: 'داكن',
  },
  crumb: 'لوحة التحكم',
  lang: 'ar' as const,
  setLang: vi.fn(),
  theme: 'light' as const,
  setTheme: vi.fn(),
  textscale: 'md' as const,
  setTextscale: vi.fn(),
  userName: 'مدير النظام',
  userRole: 'مدير النظام',
  userInitials: 'ما',
  userMenuLabels: { profile: 'الملف الشخصي', settings: 'الإعدادات', logout: 'تسجيل الخروج' },
  orgMenuLabels: { switch: 'تبديل المؤسسة', manage: 'إدارة المؤسسات' },
  t: (key: string, fallback?: string) => fallback ?? key,
};

afterEach(() => {
  vi.clearAllMocks();
});

describe('nasaq App keyboard shortcuts', () => {
  it('opens the command palette on Cmd/Ctrl+K', () => {
    render(<App {...baseProps} />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'k', metaKey: true });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('does not crash on a keydown event that has no `key` (browser autofill / IME)', () => {
    render(<App {...baseProps} />);
    const onError = vi.fn();
    window.addEventListener('error', onError);
    try {
      // A plain Event dispatched as "keydown" has `key === undefined`. Before the
      // guard this threw `Cannot read properties of undefined (reading 'toLowerCase')`
      // inside the listener, surfacing as an uncaught window error.
      document.dispatchEvent(new Event('keydown'));
    } finally {
      window.removeEventListener('error', onError);
    }
    expect(onError).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    // The shortcut still works afterwards.
    fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('shows a technical dashboard button for admins only', () => {
    const onTechnicalDashboard = vi.fn();
    const { rerender } = render(
      <App
        {...baseProps}
        isAdmin={false}
        technicalDashboardLabel="اللوحة التقنية"
        onTechnicalDashboard={onTechnicalDashboard}
      />,
    );

    expect(screen.queryByRole('button', { name: 'اللوحة التقنية' })).not.toBeInTheDocument();

    rerender(
      <App
        {...baseProps}
        isAdmin
        technicalDashboardLabel="اللوحة التقنية"
        onTechnicalDashboard={onTechnicalDashboard}
      />,
    );

    const button = screen.getByRole('button', { name: 'اللوحة التقنية' });
    fireEvent.click(button);

    expect(onTechnicalDashboard).toHaveBeenCalledTimes(1);
  });
});
