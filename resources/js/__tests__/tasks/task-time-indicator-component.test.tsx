import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconClock: () => <span data-testid="clock-icon">Clock</span>,
  IconAlertTriangle: () => <span data-testid="alert-icon">AlertTriangle</span>,
  IconCircleCheck: () => <span data-testid="check-icon">CheckCircle2</span>,
  IconClockHour4: () => <span data-testid="timer-icon">Timer</span>,
  IconTrendingUp: () => <span data-testid="trending-icon">TrendingUp</span>,
  IconFlame: () => <span data-testid="flame-icon">Flame</span>,

  };
});

import TaskTimeIndicator from '@widgets/task/ui/TaskTimeIndicator';

const normalIndicator = {
  days_remaining: 10,
  days_elapsed: 5,
  total_days: 15,
  time_progress: 33,
  status: 'normal' as const,
  has_due_date: true,
};

const warningIndicator = {
  days_remaining: 3,
  days_elapsed: 12,
  total_days: 15,
  time_progress: 80,
  status: 'warning' as const,
  has_due_date: true,
};

const urgentIndicator = {
  days_remaining: 1,
  days_elapsed: 14,
  total_days: 15,
  time_progress: 93,
  status: 'urgent' as const,
  has_due_date: true,
};

const overdueIndicator = {
  days_remaining: -3,
  days_elapsed: 18,
  total_days: 15,
  time_progress: 100,
  status: 'overdue' as const,
  has_due_date: true,
};

const completedIndicator = {
  days_remaining: 5,
  days_elapsed: 10,
  total_days: 15,
  time_progress: 66,
  status: 'completed' as const,
  has_due_date: true,
};

describe('TaskTimeIndicator Basic', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders with normal status', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('10 يوم')).toBeInTheDocument();
  });

  it('returns null when no due date', () => {
    const noDateIndicator = { ...normalIndicator, has_due_date: false };
    const { container } = render(<TaskTimeIndicator indicator={noDateIndicator} taskStatus="in_progress" />);
    expect(container.firstChild).toBeNull();
  });

  it('shows clock icon for normal status', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByTestId('clock-icon')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Status Variations', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows timer icon for warning status', () => {
    render(<TaskTimeIndicator indicator={warningIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByTestId('timer-icon')).toBeInTheDocument();
  });

  it('shows flame icon for urgent status', () => {
    render(<TaskTimeIndicator indicator={urgentIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByTestId('flame-icon')).toBeInTheDocument();
  });

  it('shows alert icon for overdue status', () => {
    render(<TaskTimeIndicator indicator={overdueIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByTestId('alert-icon')).toBeInTheDocument();
  });

  it('shows check icon for completed status', () => {
    render(<TaskTimeIndicator indicator={completedIndicator} taskStatus="completed" variant="standard" />);
    expect(screen.getByTestId('check-icon')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Days Text', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows "اليوم" for 0 days remaining', () => {
    const todayIndicator = { ...normalIndicator, days_remaining: 0 };
    render(<TaskTimeIndicator indicator={todayIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('اليوم')).toBeInTheDocument();
  });

  it('shows "غداً" for 1 day remaining', () => {
    const tomorrowIndicator = { ...normalIndicator, days_remaining: 1 };
    render(<TaskTimeIndicator indicator={tomorrowIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('غداً')).toBeInTheDocument();
  });

  it('shows "X أيام" for 2-7 days remaining', () => {
    const fewDaysIndicator = { ...normalIndicator, days_remaining: 5 };
    render(<TaskTimeIndicator indicator={fewDaysIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('5 أيام')).toBeInTheDocument();
  });

  it('shows "X يوم" for more than 7 days', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('10 يوم')).toBeInTheDocument();
  });

  it('shows overdue text for negative days', () => {
    render(<TaskTimeIndicator indicator={overdueIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('متأخر 3 يوم')).toBeInTheDocument();
  });

  it('shows "مكتملة" for completed tasks', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="completed" />);
    expect(screen.getByText(/مكتمل/)).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Compact Variant', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders compact variant', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="compact" />);
    expect(screen.getByText('المدة المتبقية للاستحقاق')).toBeInTheDocument();
  });

  it('shows days text in compact mode', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="compact" />);
    expect(screen.getByText('10 يوم')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Standard Variant', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders standard variant', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByText('الوقت المتبقي')).toBeInTheDocument();
  });

  it('shows days text in standard mode', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByText('10 يوم')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Detailed Variant', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders detailed variant', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('المؤشر الزمني')).toBeInTheDocument();
  });

  it('shows elapsed days in detailed mode', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('مضى 5 يوم')).toBeInTheDocument();
  });

  it('shows total days in detailed mode', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('من أصل 15 يوم')).toBeInTheDocument();
  });

  it('shows progress percentage in detailed mode', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('33%')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Status Messages', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows warning message', () => {
    render(<TaskTimeIndicator indicator={warningIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('تحتاج إلى متابعة')).toBeInTheDocument();
  });

  it('shows urgent message', () => {
    render(<TaskTimeIndicator indicator={urgentIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('تحتاج إلى إجراء عاجل')).toBeInTheDocument();
  });

  it('shows overdue message', () => {
    render(<TaskTimeIndicator indicator={overdueIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('تجاوزت الموعد المحدد')).toBeInTheDocument();
  });

  it('does not show message for normal status', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.queryByText('تحتاج إلى متابعة')).not.toBeInTheDocument();
    expect(screen.queryByText('تحتاج إلى إجراء عاجل')).not.toBeInTheDocument();
    expect(screen.queryByText('تجاوزت الموعد المحدد')).not.toBeInTheDocument();
  });

  it('does not show message for completed status', () => {
    render(<TaskTimeIndicator indicator={completedIndicator} taskStatus="completed" variant="detailed" />);
    expect(screen.queryByText('تحتاج إلى متابعة')).not.toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Progress', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows progress bar by default', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.getByTestId('task-progress-track')).toBeInTheDocument();
  });

  it('hides progress bar when showProgress is false', () => {
    render(<TaskTimeIndicator indicator={normalIndicator} showProgress={false} />);
    expect(screen.queryByTestId('task-progress-track')).not.toBeInTheDocument();
  });
});

describe('TaskTimeIndicator No Total Days', () => {
  const noTotalIndicator = {
    ...normalIndicator,
    total_days: 0,
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('hides progress in standard when no total days', () => {
    render(<TaskTimeIndicator indicator={noTotalIndicator} taskStatus="in_progress" variant="standard" />);
    expect(screen.queryByTestId('task-progress-track')).not.toBeInTheDocument();
  });

  it('hides circular progress in detailed when no total days', () => {
    render(<TaskTimeIndicator indicator={noTotalIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.queryByText('33%')).not.toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Null Days', () => {
  const nullDaysIndicator = {
    ...normalIndicator,
    days_remaining: null,
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows dash for null days remaining', () => {
    render(<TaskTimeIndicator indicator={nullDaysIndicator} taskStatus="in_progress" />);
    expect(screen.getByText('-')).toBeInTheDocument();
  });
});

describe('TaskTimeIndicator Custom Class', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('applies custom className', () => {
    const { container } = render(
      <TaskTimeIndicator indicator={normalIndicator} taskStatus="in_progress" className="custom-class" />
    );
    expect(container.firstChild).toHaveClass('custom-class');
  });
});

describe('TaskTimeIndicator Progress Capping', () => {
  const overProgressIndicator = {
    ...normalIndicator,
    time_progress: 120, // أكثر من 100%
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('caps progress at 100%', () => {
    render(<TaskTimeIndicator indicator={overProgressIndicator} taskStatus="in_progress" variant="detailed" />);
    expect(screen.getByText('100%')).toBeInTheDocument();
  });
});
