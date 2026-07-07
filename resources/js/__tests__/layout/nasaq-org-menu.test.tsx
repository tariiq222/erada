import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { OrgMenu, type NasaqOrgOption } from '@shared/nasaq/app';

const labels = { switch: 'تبديل المؤسسة', manage: 'إدارة المؤسسات' };

const twoOrgs: NasaqOrgOption[] = [
  { id: 1, name: 'المؤسسة الافتراضية', code: 'DEFAULT' },
  { id: 2, name: 'مؤسسة الاختبار', code: 'TEST' },
];

function renderMenu(overrides: Partial<React.ComponentProps<typeof OrgMenu>> = {}) {
  const onSwitchOrg = vi.fn();
  const onManageOrgs = vi.fn();
  render(
    <OrgMenu
      orgName="المؤسسة الافتراضية"
      orgMeta="DEFAULT"
      organizations={twoOrgs}
      currentOrgId={1}
      labels={labels}
      onSwitchOrg={onSwitchOrg}
      onManageOrgs={onManageOrgs}
      {...overrides}
    />,
  );
  return { onSwitchOrg, onManageOrgs };
}

describe('OrgMenu', () => {
  it('renders a static chip (no button) when there is one org and caller is not admin', () => {
    renderMenu({ organizations: [{ id: 1, name: 'المؤسسة الافتراضية', code: 'DEFAULT' }], isAdmin: false });
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('المؤسسة الافتراضية')).toBeInTheDocument();
  });

  it('is interactive for an admin even with a single org', () => {
    const { onManageOrgs } = renderMenu({
      organizations: [{ id: 1, name: 'المؤسسة الافتراضية', code: 'DEFAULT' }],
      isAdmin: true,
    });
    const chip = screen.getByRole('button', { expanded: false });
    fireEvent.click(chip);
    expect(screen.getByRole('menu')).toBeInTheDocument();
    fireEvent.click(screen.getByText(labels.manage));
    expect(onManageOrgs).toHaveBeenCalledTimes(1);
  });

  it('opens a switcher listing all organizations with the current one checked', () => {
    renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    const items = screen.getAllByRole('menuitemradio');
    expect(items).toHaveLength(2);
    const current = screen.getByRole('menuitemradio', { checked: true });
    expect(current).toHaveTextContent('المؤسسة الافتراضية');
  });

  it('switches to another organization on click and closes the menu', () => {
    const { onSwitchOrg } = renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    fireEvent.click(screen.getByText('مؤسسة الاختبار'));
    expect(onSwitchOrg).toHaveBeenCalledWith(2);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('does not fire a switch when the already-current organization is clicked', () => {
    const { onSwitchOrg } = renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    fireEvent.click(screen.getByRole('menuitemradio', { checked: true }));
    expect(onSwitchOrg).not.toHaveBeenCalled();
  });

  it('closes the menu on Escape', () => {
    renderMenu();
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });
});
