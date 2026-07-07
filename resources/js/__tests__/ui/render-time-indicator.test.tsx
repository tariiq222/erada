import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

// Mock i18next directly (component uses i18next.t.bind(i18next), not react-i18next hook)
vi.mock('i18next', () => {
  const translations: Record<string, string> = {
    'projects.time_completed': 'مكتملة',
    'projects.time_overdue_days': 'متأخر {{count}} يوم',
    'projects.time_today': 'اليوم',
    'projects.time_tomorrow': 'غداً',
    'projects.time_days_remaining': '{{count}} يوم',
    'projects.time_until_due': 'المدة المتبقية للاستحقاق',
  };
  const tFn = (key: string, opts?: Record<string, unknown>) => {
    let val = translations[key] ?? key;
    if (opts) {
      Object.entries(opts).forEach(([k, v]) => {
        val = val.replace(`{{${k}}}`, String(v));
      });
    }
    return val;
  };
  return {
    default: { t: tFn },
  };
});

// Mock the constants
vi.mock('@pages/projects/constants', () => ({
  timeIndicatorColors: {
    normal: { bg: 'bg-gray-200', fill: 'bg-cyan-500', text: 'text-gray-600' },
    warning: { bg: 'bg-amber-100', fill: 'bg-amber-500', text: 'text-amber-600' },
    urgent: { bg: 'bg-orange-100', fill: 'bg-orange-500', text: 'text-orange-600' },
    overdue: { bg: 'bg-red-100', fill: 'bg-red-500', text: 'text-red-600' },
    completed: { bg: 'bg-emerald-100', fill: 'bg-emerald-500', text: 'text-emerald-600' },
  },
}));

import { renderTimeIndicator } from '@pages/projects/components/sections/tasks/renderTimeIndicator';

describe('renderTimeIndicator - Returns null', () => {
  it('returns null when indicator is undefined', () => {
    const result = renderTimeIndicator(undefined, 'todo');
    expect(result).toBeNull();
  });

  it('returns null when indicator is null', () => {
    const result = renderTimeIndicator(null as any, 'todo');
    expect(result).toBeNull();
  });

  it('returns null when has_due_date is false', () => {
    const indicator = {
      has_due_date: false,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: 10,
    };
    const result = renderTimeIndicator(indicator, 'todo');
    expect(result).toBeNull();
  });

  it('returns null when has_due_date is undefined', () => {
    const indicator = {
      has_due_date: undefined,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: 10,
    };
    const result = renderTimeIndicator(indicator as any, 'todo');
    expect(result).toBeNull();
  });
});

describe('renderTimeIndicator - Days Text', () => {
  it('shows "مكتملة" when task status is completed', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'completed',
      time_progress: 100,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'completed')}</>);
    expect(container.textContent).toContain('مكتملة');
  });

  it('shows "-" when days_remaining is null', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: null,
      status: 'normal',
      time_progress: 50,
      total_days: null,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('-');
  });

  it('shows "متأخر X يوم" when days_remaining is negative', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: -3,
      status: 'overdue',
      time_progress: 100,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('متأخر 3 يوم');
  });

  it('shows "اليوم" when days_remaining is 0', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 0,
      status: 'urgent',
      time_progress: 100,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('اليوم');
  });

  it('shows "غداً" when days_remaining is 1', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 1,
      status: 'urgent',
      time_progress: 90,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('غداً');
  });

  it('shows "X يوم" when days_remaining is greater than 1', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('5 يوم');
  });

  it('shows days remaining for large numbers', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 30,
      status: 'normal',
      time_progress: 10,
      total_days: 60,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('30 يوم');
  });
});

describe('renderTimeIndicator - Progress Bar', () => {
  it('renders progress bar when total_days is set', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('5 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    const fill = track!.firstElementChild;
    expect(track).toHaveClass('bg-gray-200');
    expect(fill).toHaveClass('bg-cyan-500');
  });

  it('does not render progress bar when total_days is null', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: null,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('5 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const placeholder = daysText.parentElement!.firstElementChild;
    // When total_days is null, no fill child is rendered inside the placeholder
    expect(placeholder!.firstElementChild).toBeNull();
  });

  it('caps progress at 100%', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: -5,
      status: 'overdue',
      time_progress: 150,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('متأخر 5 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    const fill = track!.firstElementChild;
    expect(fill).toHaveClass('bg-red-500');
    expect(fill).toHaveStyle({ width: '100%' });
  });

  it('handles 0% progress', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 10,
      status: 'normal',
      time_progress: 0,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('10 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    const fill = track!.firstElementChild;
    expect(fill).toHaveClass('bg-cyan-500');
    expect(fill).toHaveStyle({ width: '0%' });
  });

  it('handles undefined time_progress', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'normal',
      time_progress: undefined,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator as any, 'todo')}</>);
    const daysText = screen.getByText('5 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    const fill = track!.firstElementChild;
    expect(fill).toHaveClass('bg-cyan-500');
    expect(fill).toHaveStyle({ width: '0%' });
  });
});

describe('renderTimeIndicator - Status Colors', () => {
  it('uses normal colors for normal status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 10,
      status: 'normal',
      time_progress: 20,
      total_days: 20,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('10 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-gray-200');
    expect(daysText).toHaveClass('text-gray-600');
  });

  it('uses warning colors for warning status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 3,
      status: 'warning',
      time_progress: 70,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('3 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-amber-100');
    expect(daysText).toHaveClass('text-amber-600');
  });

  it('uses urgent colors for urgent status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 1,
      status: 'urgent',
      time_progress: 90,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('غداً');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-orange-100');
    expect(daysText).toHaveClass('text-orange-600');
  });

  it('uses overdue colors for overdue status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: -2,
      status: 'overdue',
      time_progress: 100,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('متأخر 2 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-red-100');
    expect(daysText).toHaveClass('text-red-600');
  });

  it('uses completed colors for completed status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 0,
      status: 'completed',
      time_progress: 100,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'completed')}</>);
    const daysText = screen.getByText('مكتملة');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-emerald-100');
    expect(daysText).toHaveClass('text-emerald-600');
  });

  it('falls back to normal colors for unknown status', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'unknown_status',
      time_progress: 50,
      total_days: 10,
    };
    render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    const daysText = screen.getByText('5 يوم');
    // kept: tests DOM layout the screen query API cannot reach
    const track = daysText.parentElement!.firstElementChild;
    expect(track).toHaveClass('bg-gray-200');
  });
});

describe('renderTimeIndicator - Label', () => {
  it('shows "المدة المتبقية للاستحقاق" label', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'normal',
      time_progress: 50,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('المدة المتبقية للاستحقاق');
  });
});

describe('renderTimeIndicator - Edge Cases', () => {
  it('handles negative days_remaining for overdue tasks', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: -10,
      status: 'overdue',
      time_progress: 200,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('متأخر 10 يوم');
  });

  it('handles very large days_remaining', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 365,
      status: 'normal',
      time_progress: 1,
      total_days: 400,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'todo')}</>);
    expect(container.textContent).toContain('365 يوم');
  });

  it('handles completed task with remaining days', () => {
    const indicator = {
      has_due_date: true,
      days_remaining: 5,
      status: 'completed',
      time_progress: 100,
      total_days: 10,
    };
    const { container } = render(<>{renderTimeIndicator(indicator, 'completed')}</>);
    // Should show "مكتملة" regardless of days_remaining
    expect(container.textContent).toContain('مكتملة');
    expect(container.textContent).not.toContain('5 يوم');
  });
});
