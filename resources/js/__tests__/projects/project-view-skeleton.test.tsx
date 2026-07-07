import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

// Mock UI components
vi.mock('@shared/ui', () => ({
  Card: ({ children, className }: any) => <div className={className} data-testid="card">{children}</div>,
  CardContent: ({ children, className }: any) => <div className={className} data-testid="card-content">{children}</div>,
  Skeleton: ({ width, height, className }: any) => (
    <div
      data-testid="skeleton"
      data-width={width}
      data-height={height}
      className={className}
    />
  ),
  SkeletonText: ({ lines }: any) => (
    <div data-testid="skeleton-text" data-lines={lines} />
  ),
}));

import ProjectViewSkeleton from '@pages/projects/components/ProjectViewSkeleton';

describe('ProjectViewSkeleton', () => {
  it('renders skeleton component', () => {
    render(<ProjectViewSkeleton />);
    expect(screen.getAllByTestId('skeleton').length).toBeGreaterThan(0);
  });

  it('renders breadcrumb skeleton (width 300)', () => {
    render(<ProjectViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const breadcrumbSkeleton = skeletons.find(s => s.getAttribute('data-width') === '300');
    expect(breadcrumbSkeleton).toBeInTheDocument();
  });

  it('renders title skeleton (width 250)', () => {
    render(<ProjectViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const titleSkeleton = skeletons.find(s => s.getAttribute('data-width') === '250');
    expect(titleSkeleton).toBeInTheDocument();
  });

  it('renders button skeleton (width 100, height 40)', () => {
    render(<ProjectViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const buttonSkeleton = skeletons.find(s =>
      s.getAttribute('data-width') === '100' && s.getAttribute('data-height') === '40'
    );
    expect(buttonSkeleton).toBeInTheDocument();
  });

  it('renders cards', () => {
    render(<ProjectViewSkeleton />);
    const cards = screen.getAllByTestId('card');
    expect(cards.length).toBeGreaterThanOrEqual(5);
  });

  it('renders 4 stat cards in grid', () => {
    render(<ProjectViewSkeleton />);
    const cardContents = screen.getAllByTestId('card-content');
    // Should have at least 5 card contents (1 main + 4 stats)
    expect(cardContents.length).toBeGreaterThanOrEqual(5);
  });

  it('renders skeleton text with 5 lines', () => {
    render(<ProjectViewSkeleton />);
    const skeletonText = screen.getByTestId('skeleton-text');
    expect(skeletonText).toHaveAttribute('data-lines', '5');
  });

  it('renders description card section title skeleton', () => {
    render(<ProjectViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const sectionTitleSkeleton = skeletons.find(s =>
      s.getAttribute('data-width') === '200' && s.getAttribute('data-height') === '24'
    );
    expect(sectionTitleSkeleton).toBeInTheDocument();
  });
});
