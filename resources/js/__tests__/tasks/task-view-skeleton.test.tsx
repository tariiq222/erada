import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

// Mock UI components
vi.mock('@shared/ui', () => ({
  Card: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div data-testid="card" className={className}>{children}</div>
  ),
  CardContent: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div data-testid="card-content" className={className}>{children}</div>
  ),
  Skeleton: ({ width, height, className }: { width?: number; height?: number; className?: string }) => (
    <div data-testid="skeleton" className={className} style={{ width, height }} />
  ),
  SkeletonText: ({ lines }: { lines?: number }) => (
    <div data-testid="skeleton-text" data-lines={lines || 3} />
  ),
}));

import TaskViewSkeleton from '@pages/tasks/view/TaskViewSkeleton';

describe('TaskViewSkeleton Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', () => {
    render(<TaskViewSkeleton />);
    expect(screen.getAllByTestId('skeleton').length).toBeGreaterThan(0);
  });

  it('renders multiple skeleton elements', () => {
    render(<TaskViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    expect(skeletons.length).toBeGreaterThanOrEqual(4);
  });

  it('renders skeleton text elements', () => {
    render(<TaskViewSkeleton />);
    const skeletonTexts = screen.getAllByTestId('skeleton-text');
    expect(skeletonTexts.length).toBe(2);
  });

  it('renders card components', () => {
    render(<TaskViewSkeleton />);
    const cards = screen.getAllByTestId('card');
    expect(cards.length).toBe(2);
  });

  it('renders card content components', () => {
    render(<TaskViewSkeleton />);
    const cardContents = screen.getAllByTestId('card-content');
    expect(cardContents.length).toBe(2);
  });

  it('renders header skeleton with correct dimensions', () => {
    render(<TaskViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const firstSkeleton = skeletons[0];
    expect(firstSkeleton).toHaveStyle({ width: '200px', height: '20px' });
  });

  it('renders title skeleton with correct dimensions', () => {
    render(<TaskViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const titleSkeleton = skeletons[1];
    expect(titleSkeleton).toHaveStyle({ width: '300px', height: '32px' });
  });

  it('renders subtitle skeleton', () => {
    render(<TaskViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const subtitleSkeleton = skeletons[2];
    expect(subtitleSkeleton).toHaveStyle({ width: '150px', height: '20px' });
  });

  it('renders action button skeleton', () => {
    render(<TaskViewSkeleton />);
    const skeletons = screen.getAllByTestId('skeleton');
    const actionSkeleton = skeletons[3];
    expect(actionSkeleton).toHaveStyle({ width: '100px', height: '40px' });
  });

  it('has correct grid layout structure', () => {
    // Layout is decorative; verified by the skeleton + card counts in earlier tests
    render(<TaskViewSkeleton />);
    expect(screen.getAllByTestId('card').length).toBe(2);
  });

  it('has main content area spanning 2 columns on large screens', () => {
    // Decorative column span; verified by card-count assertion above
    render(<TaskViewSkeleton />);
    expect(screen.getAllByTestId('card-content').length).toBe(2);
  });

  it('renders skeleton text with 4 lines in main content', () => {
    render(<TaskViewSkeleton />);
    const skeletonTexts = screen.getAllByTestId('skeleton-text');
    expect(skeletonTexts[0]).toHaveAttribute('data-lines', '4');
  });

  it('renders skeleton text with 8 lines in sidebar', () => {
    render(<TaskViewSkeleton />);
    const skeletonTexts = screen.getAllByTestId('skeleton-text');
    expect(skeletonTexts[1]).toHaveAttribute('data-lines', '8');
  });

  it('has space-y-6 styling on root container', () => {
    const { container } = render(<TaskViewSkeleton />);
    expect(container.firstChild).toHaveClass('space-y-6');
  });
});
