import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { UserMenu } from '@shared/nasaq/app';

const labels = {
  profile: 'الملف الشخصي',
  settings: 'الإعدادات',
  logout: 'تسجيل الخروج',
};

function renderMenu(overrides: Partial<React.ComponentProps<typeof UserMenu>> = {}) {
  const onProfile = vi.fn();
  const onSettings = vi.fn();
  const onLogout = vi.fn();
  render(
    <UserMenu
      userName="مدير النظام"
      userRole="مدير النظام"
      userInitials="ما"
      labels={labels}
      onProfile={onProfile}
      onSettings={onSettings}
      onLogout={onLogout}
      {...overrides}
    />,
  );
  return { onProfile, onSettings, onLogout };
}

describe('UserMenu', () => {
  it('renders the user chip as a button and keeps the menu closed initially', () => {
    renderMenu();
    const chip = screen.getByRole('button', { expanded: false });
    expect(chip).toBeInTheDocument();
    expect(chip).toHaveAttribute('aria-haspopup', 'menu');
    // The bug: clicking did nothing because there was no menu. Assert it is absent until opened.
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.queryByText(labels.profile)).not.toBeInTheDocument();
  });

  it('opens the dropdown menu when the chip is clicked', () => {
    renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(screen.getByText(labels.profile)).toBeInTheDocument();
    expect(screen.getByText(labels.logout)).toBeInTheDocument();
  });

  it('invokes onProfile and closes the menu when profile is clicked', () => {
    const { onProfile } = renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    fireEvent.click(screen.getByText(labels.profile));
    expect(onProfile).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('invokes onLogout when logout is clicked', () => {
    const { onLogout } = renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    fireEvent.click(screen.getByText(labels.logout));
    expect(onLogout).toHaveBeenCalledTimes(1);
  });

  it('hides the settings item for non-admins', () => {
    renderMenu({ isAdmin: false });
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByRole('menu')).toBeInTheDocument(); // menu is open
    expect(screen.queryByText(labels.settings)).not.toBeInTheDocument();
  });

  it('shows the settings item and fires onSettings for admins', () => {
    const { onSettings } = renderMenu({ isAdmin: true });
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    const settings = screen.getByText(labels.settings);
    expect(settings).toBeInTheDocument();
    fireEvent.click(settings);
    expect(onSettings).toHaveBeenCalledTimes(1);
  });

  it('closes the menu on Escape', () => {
    renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });
});
