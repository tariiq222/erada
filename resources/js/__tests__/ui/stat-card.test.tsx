import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import {IconLayoutKanban, IconUsers, IconSquareCheck, IconAlertTriangle, IconClock} from '@tabler/icons-react';

// Mock UI components
vi.mock('@shared/ui', () => ({
  Card: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="card">{children}</div>
  ),
  CardContent: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div className={className}>{children}</div>
  ),
}));

import { StatCard, StatCardColor } from '@shared/ui/StatCard';

describe('StatCard', () => {
  it('renders label', () => {
    render(<StatCard label="المشاريع" value={10} icon={IconLayoutKanban} />);
    expect(screen.getByText('المشاريع')).toBeInTheDocument();
  });

  it('renders numeric value', () => {
    render(<StatCard label="المشاريع" value={25} icon={IconLayoutKanban} />);
    expect(screen.getByText('25')).toBeInTheDocument();
  });

  it('renders string value', () => {
    render(<StatCard label="الحالة" value="نشط" icon={IconSquareCheck} />);
    expect(screen.getByText('نشط')).toBeInTheDocument();
  });

  it('renders with default accent color', () => {
    render(<StatCard label="Test" value={1} icon={IconUsers} />);
    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'accent');
  });

  it('renders with success color', () => {
    render(<StatCard label="Test" value={1} icon={IconUsers} color="success" />);
    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'success');
  });

  it('renders with warning color', () => {
    render(<StatCard label="Test" value={1} icon={IconAlertTriangle} color="warning" />);
    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'warning');
  });

  it('renders with danger color', () => {
    render(<StatCard label="Test" value={1} icon={IconAlertTriangle} color="danger" />);
    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'danger');
  });

  it('renders with info color', () => {
    render(<StatCard label="Test" value={1} icon={IconClock} color="info" />);
    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'info');
  });

  it('renders inside a card', () => {
    render(<StatCard label="Test" value={1} icon={IconUsers} />);
    expect(screen.getByTestId('card')).toBeInTheDocument();
  });

  it('renders with large numeric value', () => {
    render(<StatCard label="المشاريع" value={1000000} icon={IconLayoutKanban} />);
    expect(screen.getByText('1000000')).toBeInTheDocument();
  });

  it('renders with zero value', () => {
    render(<StatCard label="المشاريع" value={0} icon={IconLayoutKanban} />);
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('renders with negative value', () => {
    render(<StatCard label="التغيير" value={-5} icon={IconAlertTriangle} />);
    expect(screen.getByText('-5')).toBeInTheDocument();
  });
});

describe('StatCard Colors', () => {
  const colors: StatCardColor[] = ['accent', 'success', 'warning', 'danger', 'info'];

  colors.forEach((color) => {
    it(`renders with ${color} color`, () => {
      render(<StatCard label="Test" value={1} icon={IconUsers} color={color} />);
      // Icon container is rendered for any color variant
      expect(screen.getByText('Test')).toBeInTheDocument();
    });
  });
});

describe('StatCard Icons', () => {
  it('renders IconLayoutKanban icon', () => {
    render(<StatCard label="Test" value={1} icon={IconLayoutKanban} />);
    expect(screen.getByTestId('stat-card-icon').querySelector('svg')).toBeInTheDocument();
  });

  it('renders IconUsers icon', () => {
    render(<StatCard label="Test" value={1} icon={IconUsers} />);
    expect(screen.getByTestId('stat-card-icon').querySelector('svg')).toBeInTheDocument();
  });

  it('renders IconSquareCheck icon', () => {
    render(<StatCard label="Test" value={1} icon={IconSquareCheck} />);
    expect(screen.getByTestId('stat-card-icon').querySelector('svg')).toBeInTheDocument();
  });

  it('renders IconAlertTriangle icon', () => {
    render(<StatCard label="Test" value={1} icon={IconAlertTriangle} />);
    expect(screen.getByTestId('stat-card-icon').querySelector('svg')).toBeInTheDocument();
  });
});
