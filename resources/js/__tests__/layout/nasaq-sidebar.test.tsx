import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Sidebar, type NavGroup } from '@shared/nasaq/app';

const labels = {
  brandName: 'إرادة',
  brandSub: 'منصة إرادة',
  search: 'بحث',
  sectionOps: 'العمليات',
  sectionAdmin: 'الإدارة',
  collapse: 'طي',
  expand: 'توسيع',
};

const groups: NavGroup[] = [
  {
    id: 'main',
    items: [{ key: 'dashboard', label: 'لوحة التحكم', icon: 'grid', path: '/dashboard' }],
  },
  {
    id: 'ops',
    label: 'العمليات',
    items: [
      {
        key: 'projects',
        label: 'المشاريع',
        icon: 'folder',
        path: '/projects',
        children: [
          { key: 'projects-all', label: 'كل المشاريع', icon: 'list', path: '/projects' },
          { key: 'projects-new', label: 'مشاريع تطويرية', icon: 'flag', path: '/projects?type=development' },
        ],
      },
      {
        key: 'risks',
        label: 'المخاطر',
        icon: 'shield',
        path: '/risk-management',
        children: [{ key: 'risks-all', label: 'قائمة المخاطر', icon: 'list', path: '/risk-management/risks' }],
      },
    ],
  },
  {
    id: 'admin',
    label: 'الإدارة',
    items: [{ key: 'users', label: 'المستخدمون', icon: 'users', path: '/admin/users' }],
  },
];

const renderSidebar = (activePath: string, activeHref = activePath) =>
  render(
    <Sidebar
      labels={labels}
      groups={groups}
      activePath={activePath}
      activeHref={activeHref}
      onNavigate={vi.fn()}
    />,
  );

describe('Nasaq Sidebar', () => {
  it('renders group section labels', () => {
    renderSidebar('/dashboard');
    expect(screen.getByText('العمليات')).toBeInTheDocument();
    expect(screen.getByText('الإدارة')).toBeInTheDocument();
  });

  it('keeps manually opened accordion sections open', () => {
    renderSidebar('/dashboard');

    // Nothing expanded initially.
    expect(screen.queryByText('كل المشاريع')).not.toBeInTheDocument();
    expect(screen.queryByText('قائمة المخاطر')).not.toBeInTheDocument();

    const projectsButton = screen.getByRole('button', { name: 'المشاريع' });
    const risksButton = screen.getByRole('button', { name: 'المخاطر' });

    fireEvent.click(projectsButton);
    expect(screen.getByText('كل المشاريع')).toBeInTheDocument();
    expect(projectsButton).toHaveAttribute('aria-expanded', 'true');

    fireEvent.click(risksButton);
    expect(screen.getByText('قائمة المخاطر')).toBeInTheDocument();
    expect(screen.getByText('كل المشاريع')).toBeInTheDocument();
    expect(projectsButton).toHaveAttribute('aria-expanded', 'true');
    expect(risksButton).toHaveAttribute('aria-expanded', 'true');
  });

  it('auto-opens the active branch and keeps siblings collapsed', () => {
    renderSidebar('/risk-management/risks');
    expect(screen.getByText('قائمة المخاطر')).toBeInTheDocument();
    expect(screen.queryByText('كل المشاريع')).not.toBeInTheDocument();
  });
});
