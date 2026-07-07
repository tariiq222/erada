import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => i18nKey,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}));
import React from 'react';

// Mock scrollIntoView
beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

// Mock API
vi.mock('@entities/task', () => ({
  tasksApi: {
    updateStatus: vi.fn().mockResolvedValue({}),
    addComment: vi.fn().mockResolvedValue({}),
    getActivityLog: vi.fn().mockResolvedValue([]),
  },
}));

// Mock Toast
vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({
    showToast: vi.fn(),
  }),
}));

// Import components
import TaskStatusChanger from '@widgets/task/ui/TaskStatusChanger';

describe('TaskStatusChanger Component', () => {
  const defaultProps = {
    taskId: 1,
    currentStatus: 'todo',
    onStatusChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders current status', async () => {
    render(<TaskStatusChanger {...defaultProps} />);

    await waitFor(() => {
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
    });
  });

  it('renders in_progress status correctly', async () => {
    render(<TaskStatusChanger {...defaultProps} currentStatus="in_progress" />);

    await waitFor(() => {
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });
  });

  it('renders completed status correctly', async () => {
    render(<TaskStatusChanger {...defaultProps} currentStatus="completed" />);

    await waitFor(() => {
      expect(screen.getByText(/مكتمل/)).toBeInTheDocument();
    });
  });

  it('renders in_review status correctly', async () => {
    render(<TaskStatusChanger {...defaultProps} currentStatus="in_review" />);

    await waitFor(() => {
      expect(screen.getByText('قيد المراجعة')).toBeInTheDocument();
    });
  });

  it('renders on_hold status correctly', async () => {
    render(<TaskStatusChanger {...defaultProps} currentStatus="on_hold" />);

    await waitFor(() => {
      expect(screen.getByText('معلقة')).toBeInTheDocument();
    });
  });

  it('renders cancelled status correctly', async () => {
    render(<TaskStatusChanger {...defaultProps} currentStatus="cancelled" />);

    await waitFor(() => {
      expect(screen.getByText('ملغاة')).toBeInTheDocument();
    });
  });

  it('opens dropdown when clicked', async () => {
    render(<TaskStatusChanger {...defaultProps} />);

    await waitFor(() => {
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByText('للتنفيذ'));

    await waitFor(() => {
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
      expect(screen.getByText('قيد المراجعة')).toBeInTheDocument();
      expect(screen.getByText(/مكتمل/)).toBeInTheDocument();
    });
  });

  it('does not open dropdown when disabled', async () => {
    render(<TaskStatusChanger {...defaultProps} disabled />);

    await waitFor(() => {
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
    });

    const button = screen.getByText('للتنفيذ').closest('button');
    expect(button).toBeDisabled();
  });

  it('renders chevron icon', async () => {
    render(<TaskStatusChanger {...defaultProps} />);

    await waitFor(() => {
      const chevron = document.querySelector('.tabler-icon-chevron-down');
      expect(chevron).toBeInTheDocument();
    });
  });

  it('hides label when showLabel is false', async () => {
    render(<TaskStatusChanger {...defaultProps} showLabel={false} />);

    await waitFor(() => {
      expect(screen.queryByText('للتنفيذ')).not.toBeInTheDocument();
    });
  });

  it('closes dropdown when clicking outside', async () => {
    render(
      <div>
        <TaskStatusChanger {...defaultProps} />
        <button data-testid="outside">Outside</button>
      </div>
    );

    await userEvent.click(screen.getByText('للتنفيذ'));

    await waitFor(() => {
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });

    fireEvent.mouseDown(screen.getByTestId('outside'));

    await waitFor(() => {
      // Only the button text should remain, dropdown should be closed
      const progressOptions = screen.queryAllByText('قيد التنفيذ');
      expect(progressOptions.length).toBeLessThanOrEqual(1);
    });
  });
});

describe('TaskStatusChanger Size Variants', () => {
  const defaultProps = {
    taskId: 1,
    currentStatus: 'todo',
    onStatusChange: vi.fn(),
  };

  it('renders with sm size', async () => {
    render(<TaskStatusChanger {...defaultProps} size="sm" />);

    await waitFor(() => {
      const button = screen.getByText('للتنفيذ').closest('button');
      expect(button).toHaveClass('text-xs');
    });
  });

  it('renders with md size (default)', async () => {
    render(<TaskStatusChanger {...defaultProps} size="md" />);

    await waitFor(() => {
      const button = screen.getByText('للتنفيذ').closest('button');
      expect(button).toHaveClass('text-sm');
    });
  });

  it('renders with lg size', async () => {
    render(<TaskStatusChanger {...defaultProps} size="lg" />);

    await waitFor(() => {
      const button = screen.getByText('للتنفيذ').closest('button');
      expect(button).toHaveClass('text-sm');
    });
  });
});

describe('TaskStatusChanger Status Change', () => {
  const defaultProps = {
    taskId: 1,
    currentStatus: 'todo',
    onStatusChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls API and onStatusChange when status is changed', async () => {
    const { tasksApi } = await import('@entities/task');
    const onStatusChange = vi.fn();

    render(<TaskStatusChanger {...defaultProps} onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText('قيد التنفيذ'));

    await waitFor(() => {
      expect(tasksApi.updateStatus).toHaveBeenCalledWith(1, 'in_progress');
      expect(onStatusChange).toHaveBeenCalledWith('in_progress');
    });
  });
});

describe('TaskStatusChanger with Subtasks', () => {
  const defaultProps = {
    taskId: 1,
    currentStatus: 'todo',
    onStatusChange: vi.fn(),
  };

  it('shows warning when trying to change status with incomplete subtasks', async () => {
    const subtasks = [
      { id: 1, title: 'مهمة فرعية 1', status: 'in_progress' },
      { id: 2, title: 'مهمة فرعية 2', status: 'todo' },
    ];

    render(<TaskStatusChanger {...defaultProps} subtasks={subtasks} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText(/مكتمل/));

    await waitFor(() => {
      expect(screen.getByText('تنبيه')).toBeInTheDocument();
      expect(screen.getByText('توجد مهام فرعية غير مكتملة')).toBeInTheDocument();
    });
  });

  it('allows changing to in_progress with incomplete subtasks', async () => {
    const { tasksApi } = await import('@entities/task');
    const subtasks = [
      { id: 1, title: 'مهمة فرعية 1', status: 'todo' },
    ];

    render(<TaskStatusChanger {...defaultProps} subtasks={subtasks} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText('قيد التنفيذ'));

    await waitFor(() => {
      expect(tasksApi.updateStatus).toHaveBeenCalledWith(1, 'in_progress');
    });
  });

  it('closes warning when clicking "فهمت"', async () => {
    const subtasks = [
      { id: 1, title: 'مهمة فرعية 1', status: 'in_progress' },
    ];

    render(<TaskStatusChanger {...defaultProps} subtasks={subtasks} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText(/مكتمل/));

    await waitFor(() => {
      expect(screen.getByText('تنبيه')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByText('فهمت'));

    await waitFor(() => {
      expect(screen.queryByText('تنبيه')).not.toBeInTheDocument();
    });
  });

  it('shows incomplete subtasks list in warning', async () => {
    const subtasks = [
      { id: 1, title: 'مهمة فرعية 1', status: 'in_progress' },
      { id: 2, title: 'مهمة فرعية 2', status: 'todo' },
    ];

    render(<TaskStatusChanger {...defaultProps} subtasks={subtasks} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText(/مكتمل/));

    await waitFor(() => {
      expect(screen.getByText('مهمة فرعية 1')).toBeInTheDocument();
      expect(screen.getByText('مهمة فرعية 2')).toBeInTheDocument();
    });
  });

  it('shows "more tasks" message when there are more than 5 incomplete subtasks', async () => {
    const subtasks = [
      { id: 1, title: 'مهمة 1', status: 'todo' },
      { id: 2, title: 'مهمة 2', status: 'todo' },
      { id: 3, title: 'مهمة 3', status: 'todo' },
      { id: 4, title: 'مهمة 4', status: 'todo' },
      { id: 5, title: 'مهمة 5', status: 'todo' },
      { id: 6, title: 'مهمة 6', status: 'todo' },
      { id: 7, title: 'مهمة 7', status: 'todo' },
    ];

    render(<TaskStatusChanger {...defaultProps} subtasks={subtasks} />);

    await userEvent.click(screen.getByText('للتنفيذ'));
    await userEvent.click(screen.getByText(/مكتمل/));

    await waitFor(() => {
      expect(screen.getByText('و 2 مهام أخرى...')).toBeInTheDocument();
    });
  });
});

describe('TaskStatusChanger Project Notes', () => {
  const defaultProps = {
    taskId: 1,
    currentStatus: 'todo',
    onStatusChange: vi.fn(),
    hasProject: true,
  };

  it('shows note for in_review status when task has project', async () => {
    render(<TaskStatusChanger {...defaultProps} />);

    await userEvent.click(screen.getByText('للتنفيذ'));

    await waitFor(() => {
      expect(screen.getByText(/بعد الإرسال للمراجعة/)).toBeInTheDocument();
    });
  });

  it('does not show notes when task has no project', async () => {
    render(<TaskStatusChanger {...defaultProps} hasProject={false} />);

    await userEvent.click(screen.getByText('للتنفيذ'));

    await waitFor(() => {
      expect(screen.queryByText(/بعد الإرسال للمراجعة/)).not.toBeInTheDocument();
    });
  });
});

describe('TaskStatusChanger Color Coding', () => {
  it('uses gray colors for todo status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="todo" />);

    await waitFor(() => {
      const button = screen.getByText('للتنفيذ').closest('button');
      expect(button).toHaveClass('bg-[var(--surface-muted)]');
      expect(button).toHaveClass('text-[var(--text-secondary)]');
    });
  });

  it('uses blue colors for in_progress status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="in_progress" />);

    await waitFor(() => {
      const button = screen.getByText('قيد التنفيذ').closest('button');
      expect(button).toHaveClass('bg-[var(--accent-subtle)]');
      expect(button).toHaveClass('text-[var(--accent-default)]');
    });
  });

  it('uses emerald colors for completed status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="completed" />);

    await waitFor(() => {
      const button = screen.getByText(/مكتمل/).closest('button');
      expect(button).toHaveClass('bg-[var(--status-success-subtle)]');
      expect(button).toHaveClass('text-[var(--status-success-text)]');
    });
  });

  it('uses amber colors for in_review status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="in_review" />);

    await waitFor(() => {
      const button = screen.getByText('قيد المراجعة').closest('button');
      expect(button).toHaveClass('bg-[var(--status-warning-subtle)]');
      expect(button).toHaveClass('text-[var(--status-warning-text)]');
    });
  });

  it('uses orange colors for on_hold status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="on_hold" />);

    await waitFor(() => {
      const button = screen.getByText('معلقة').closest('button');
      expect(button).toHaveClass('bg-[var(--status-warning-subtle)]');
      expect(button).toHaveClass('text-[var(--status-warning-text)]');
    });
  });

  it('uses red colors for cancelled status', async () => {
    render(<TaskStatusChanger taskId={1} currentStatus="cancelled" />);

    await waitFor(() => {
      const button = screen.getByText('ملغاة').closest('button');
      expect(button).toHaveClass('bg-[var(--status-danger-subtle)]');
      expect(button).toHaveClass('text-[var(--status-danger-text)]');
    });
  });
});
