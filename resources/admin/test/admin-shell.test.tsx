import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';
import { AdminLayout } from '@admin/widgets/admin-shell/AdminLayout';
import { ADMIN_NAV_ITEMS } from '@admin/widgets/admin-shell/AdminNavigation';

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'System Admin', roles: ['super_admin'] },
    logout: vi.fn(),
  }),
}));

vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));

vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));

vi.mock('@shared/contexts/SystemSettingsContext', () => ({
  useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }),
}));

function renderShell() {
  return render(
    <MemoryRouter initialEntries={['/overview']}>
      <Routes>
        <Route element={<AdminLayout />}>
          <Route path="/overview" element={<div>Protected content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('independent admin shell', () => {
  it('uses Arabic RTL chrome and renders the protected outlet', () => {
    renderShell();

    const shell = screen.getByTestId('admin-control-plane-shell');
    expect(shell).toHaveAttribute('dir', 'rtl');
    expect(screen.getByText('Protected content')).toBeInTheDocument();
    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(screen.getByTestId('admin-desktop-navigation')).toBeInTheDocument();
  });

  it('exposes every route group in the mobile navigation from the single nav source', async () => {
    const user = userEvent.setup();
    renderShell();

    await user.click(screen.getByRole('button', { name: i18n.t('admin.shell.sidebar.aria') }));
    const mobileNavigation = screen.getByTestId('admin-mobile-navigation');

    expect(ADMIN_NAV_ITEMS).toHaveLength(12);
    for (const item of ADMIN_NAV_ITEMS) {
      expect(within(mobileNavigation).getByRole('link', {
        name: i18n.t(item.labelKey, item.fallback),
      })).toHaveAttribute(
        'href',
        item.href,
      );
    }
  });
});
