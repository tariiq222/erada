import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { BrowserRouter } from 'react-router-dom';

// Priority labels render through StatusBadge / t(), which both read from
// react-i18next. Mock it so the keys resolve to their Arabic labels.
vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'priority.low': 'منخفضة',
    'priority.medium': 'متوسطة',
    'priority.high': 'عالية',
    'priority.critical': 'حرجة',
    'priority.urgent': 'عاجلة',
  };
  return {
    useTranslation: () => ({
      t: (key: string) => translations[key] ?? key,
      i18n: { language: 'ar', changeLanguage: vi.fn() },
    }),
    Trans: ({ children }: { children?: React.ReactNode }) => children,
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconUser: () => <span data-testid="user-icon">User</span>,
  IconCalendar: () => <span data-testid="calendar-icon">Calendar</span>,
  IconFlag: () => <span data-testid="flag-icon">Flag</span>,
  IconTarget: () => <span data-testid="target-icon">Target</span>,
  IconClockHour4: () => <span data-testid="timer-icon">Timer</span>,
  IconLayoutKanban: () => <span data-testid="folder-icon">FolderKanban</span>,
  IconListTree: () => <span data-testid="listtree-icon">ListTree</span>,
  IconAlertTriangle: () => <span data-testid="alert-icon">AlertTriangle</span>,

  };
});

// Mock utils
vi.mock('@shared/lib/utils', async (importOriginal) => ({
  ...(await importOriginal<any>()),
  formatDate: (date: string) => new Date(date).toLocaleDateString('ar'),
}));

// Mock child components
vi.mock('@widgets/task/ui/TaskStatusChanger', () => ({
  default: ({ currentStatus, onStatusChange }: any) => (
    <div data-testid="status-changer">
      <span>Status: {currentStatus}</span>
      <button onClick={() => onStatusChange && onStatusChange('completed')}>Change</button>
    </div>
  ),
}));

vi.mock('@widgets/task/ui/TaskTimeIndicator', () => ({
  default: ({ indicator, variant }: any) => (
    <div data-testid="time-indicator" data-variant={variant}>
      {indicator?.status}
    </div>
  ),
}));

import TaskDetailsPanel from '@widgets/task/ui/TaskDetailsPanel';

const mockTask = {
  id: 1,
  title: 'مهمة اختبار',
  description: 'وصف المهمة',
  status: 'in_progress',
  priority: 'high',
  start_date: '2025-01-01',
  due_date: '2025-12-31',
  completed_date: null,
  estimated_hours: 20,
  actual_hours: 15,
  time_indicator: {
    days_remaining: 10,
    days_elapsed: 5,
    total_days: 15,
    time_progress: 33,
    status: 'normal' as const,
    has_due_date: true,
  },
  project: { id: 1, code: 'PRJ-001', name: 'مشروع تجريبي' },
  milestone: { id: 1, name: 'المرحلة الأولى' },
  assignee: { id: 1, name: 'أحمد محمد', email: 'ahmed@example.com' },
  creator: { id: 2, name: 'محمد علي' },
  parent: null,
  subtasks: [],
  abilities: { edit: true },
};

const renderWithRouter = (ui: React.ReactElement) => {
  return render(<BrowserRouter>{ui}</BrowserRouter>);
};

describe('TaskDetailsPanel Basic', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders status changer', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('status-changer')).toBeInTheDocument();
  });

  it('hides the status changer for users without edit ability (shows read-only badge)', () => {
    renderWithRouter(<TaskDetailsPanel task={{ ...mockTask, abilities: { edit: false } }} />);
    expect(screen.queryByTestId('status-changer')).not.toBeInTheDocument();
  });

  it('shows current status', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('Status: in_progress')).toBeInTheDocument();
  });

  it('renders time indicator when has due date', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('time-indicator')).toBeInTheDocument();
  });

  it('shows status label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('الحالة')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Task Info', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows task info header', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('معلومات المهمة')).toBeInTheDocument();
  });

  it('shows project code', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('PRJ-001')).toBeInTheDocument();
  });

  it('shows project label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('المشروع')).toBeInTheDocument();
  });

  it('shows priority label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('الأولوية')).toBeInTheDocument();
  });

  it('shows high priority in Arabic', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('عالية')).toBeInTheDocument();
  });

  it('shows assignee name', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
  });

  it('shows assignee initial', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('أ')).toBeInTheDocument();
  });

  it('shows creator name', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('محمد علي')).toBeInTheDocument();
  });

  it('shows milestone name', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('المرحلة الأولى')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Dates', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows dates header', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('التواريخ')).toBeInTheDocument();
  });

  it('shows start date label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('تاريخ البدء')).toBeInTheDocument();
  });

  it('shows due date label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('تاريخ الاستحقاق')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Hours', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows hours header', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('الساعات')).toBeInTheDocument();
  });

  it('shows estimated hours label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('المقدرة')).toBeInTheDocument();
  });

  it('shows estimated hours value', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('20 ساعة')).toBeInTheDocument();
  });

  it('shows actual hours label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('الفعلية')).toBeInTheDocument();
  });

  it('shows actual hours value', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('15 ساعة')).toBeInTheDocument();
  });

  it('shows difference label', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('الفرق')).toBeInTheDocument();
  });

  it('shows negative difference for under budget', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByText('-5 ساعة')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Hours Over Budget', () => {
  const overBudgetTask = {
    ...mockTask,
    estimated_hours: 10,
    actual_hours: 15,
  };

  it('shows positive difference for over budget', () => {
    renderWithRouter(<TaskDetailsPanel task={overBudgetTask} />);
    expect(screen.getByText('+5 ساعة')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Assignee', () => {
  const noAssigneeTask = {
    ...mockTask,
    assignee: null,
  };

  it('shows unassigned message', () => {
    renderWithRouter(<TaskDetailsPanel task={noAssigneeTask} />);
    expect(screen.getByText('غير محدد')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Project', () => {
  const noProjectTask = {
    ...mockTask,
    project: null,
  };

  it('does not show project section', () => {
    renderWithRouter(<TaskDetailsPanel task={noProjectTask} />);
    expect(screen.queryByText('PRJ-001')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Milestone', () => {
  const noMilestoneTask = {
    ...mockTask,
    milestone: null,
  };

  it('does not show milestone section', () => {
    renderWithRouter(<TaskDetailsPanel task={noMilestoneTask} />);
    expect(screen.queryByText('المرحلة الأولى')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel With Parent', () => {
  const taskWithParent = {
    ...mockTask,
    parent: { id: 5, title: 'المهمة الرئيسية' },
  };

  it('shows parent task title', () => {
    renderWithRouter(<TaskDetailsPanel task={taskWithParent} />);
    expect(screen.getByText('المهمة الرئيسية')).toBeInTheDocument();
  });

  it('shows parent task label', () => {
    renderWithRouter(<TaskDetailsPanel task={taskWithParent} />);
    expect(screen.getByText('المهمة الأم')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Overdue', () => {
  const overdueTask = {
    ...mockTask,
    due_date: '2020-01-01',
    status: 'in_progress',
  };

  it('shows overdue badge', () => {
    renderWithRouter(<TaskDetailsPanel task={overdueTask} />);
    expect(screen.getByText('متأخرة')).toBeInTheDocument();
  });

  it('shows alert icon', () => {
    renderWithRouter(<TaskDetailsPanel task={overdueTask} />);
    expect(screen.getByTestId('alert-icon')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Completed Date', () => {
  const completedTask = {
    ...mockTask,
    status: 'completed',
    completed_date: '2025-06-15',
  };

  it('shows completion date label', () => {
    renderWithRouter(<TaskDetailsPanel task={completedTask} />);
    expect(screen.getByText('تاريخ الإنجاز')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Priority Variants', () => {
  it('shows low priority', () => {
    const lowTask = { ...mockTask, priority: 'low' };
    renderWithRouter(<TaskDetailsPanel task={lowTask} />);
    expect(screen.getByText('منخفضة')).toBeInTheDocument();
  });

  it('shows medium priority', () => {
    const mediumTask = { ...mockTask, priority: 'medium' };
    renderWithRouter(<TaskDetailsPanel task={mediumTask} />);
    expect(screen.getByText('متوسطة')).toBeInTheDocument();
  });

  it('shows critical priority', () => {
    const criticalTask = { ...mockTask, priority: 'critical' };
    renderWithRouter(<TaskDetailsPanel task={criticalTask} />);
    expect(screen.getByText('حرجة')).toBeInTheDocument();
  });

  it('shows urgent priority', () => {
    const urgentTask = { ...mockTask, priority: 'urgent' };
    renderWithRouter(<TaskDetailsPanel task={urgentTask} />);
    expect(screen.getByText('عاجلة')).toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Variant', () => {
  it('uses sidebar variant by default', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('time-indicator')).toHaveAttribute('data-variant', 'detailed');
  });

  it('uses inline variant when specified', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} variant="inline" />);
    expect(screen.getByTestId('time-indicator')).toHaveAttribute('data-variant', 'standard');
  });
});

describe('TaskDetailsPanel Time Indicator Toggle', () => {
  it('shows time indicator when showTimeIndicator is true', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} showTimeIndicator={true} />);
    expect(screen.getByTestId('time-indicator')).toBeInTheDocument();
  });

  it('hides time indicator when showTimeIndicator is false', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} showTimeIndicator={false} />);
    expect(screen.queryByTestId('time-indicator')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Hours', () => {
  const noHoursTask = {
    ...mockTask,
    estimated_hours: null,
    actual_hours: null,
  };

  it('does not show hours section', () => {
    renderWithRouter(<TaskDetailsPanel task={noHoursTask} />);
    expect(screen.queryByText('الساعات')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Dates', () => {
  const noDatesTask = {
    ...mockTask,
    start_date: null,
    due_date: null,
    time_indicator: { ...mockTask.time_indicator, has_due_date: false },
  };

  it('does not show time indicator without due date', () => {
    renderWithRouter(<TaskDetailsPanel task={noDatesTask} />);
    expect(screen.queryByTestId('time-indicator')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel No Creator', () => {
  const noCreatorTask = {
    ...mockTask,
    creator: null,
  };

  it('does not show creator section', () => {
    renderWithRouter(<TaskDetailsPanel task={noCreatorTask} />);
    expect(screen.queryByText('المنشئ')).not.toBeInTheDocument();
  });
});

describe('TaskDetailsPanel Icons', () => {
  it('shows user icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getAllByTestId('user-icon').length).toBeGreaterThan(0);
  });

  it('shows calendar icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('calendar-icon')).toBeInTheDocument();
  });

  it('shows flag icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('flag-icon')).toBeInTheDocument();
  });

  it('shows target icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('target-icon')).toBeInTheDocument();
  });

  it('shows timer icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('timer-icon')).toBeInTheDocument();
  });

  it('shows folder icon', () => {
    renderWithRouter(<TaskDetailsPanel task={mockTask} />);
    expect(screen.getByTestId('folder-icon')).toBeInTheDocument();
  });
});
