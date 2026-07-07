import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

// Mock API
vi.mock('@entities/task', () => ({
  tasksApi: {
    getActivityLog: vi.fn(),
  },
}));

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconActivity: () => <span data-testid="activity-icon">Activity</span>,
  IconPencil: () => <span data-testid="edit-icon">Edit3</span>,
  IconCircleCheck: () => <span data-testid="check-icon">CheckCircle2</span>,
  IconMessage: () => <span data-testid="message-icon">MessageSquare</span>,
  IconUserPlus: () => <span data-testid="userplus-icon">UserPlus</span>,
  IconFlag: () => <span data-testid="flag-icon">Flag</span>,
  IconCalendar: () => <span data-testid="calendar-icon">Calendar</span>,
  IconLoader: () => <span data-testid="loader-icon">Loader2</span>,
  IconChevronDown: () => <span data-testid="chevron-down-icon">ChevronDown</span>,
  IconChevronUp: () => <span data-testid="chevron-up-icon">ChevronUp</span>,
  IconPlus: () => <span data-testid="plus-icon">Plus</span>,
  IconTrash: () => <span data-testid="trash-icon">Trash2</span>,
  IconPaperclip: () => <span data-testid="paperclip-icon">Paperclip</span>,
  IconListTree: () => <span data-testid="listtree-icon">ListTree</span>,

  };
});

import TaskActivityLog from '@widgets/task/ui/TaskActivityLog';
import { tasksApi } from '@entities/task';

const mockActivities = [
  {
    id: 1,
    action: 'created',
    user: { id: 1, name: 'أحمد' },
    old_values: null,
    new_values: null,
    created_at: new Date().toISOString(),
  },
  {
    id: 2,
    action: 'updated',
    user: { id: 1, name: 'أحمد' },
    old_values: { status: 'todo' },
    new_values: { status: 'in_progress' },
    created_at: new Date(Date.now() - 3600000).toISOString(),
  },
  {
    id: 3,
    action: 'comment_added',
    user: { id: 2, name: 'محمد' },
    old_values: null,
    new_values: { content: 'تعليق جديد' },
    created_at: new Date(Date.now() - 86400000).toISOString(),
  },
  {
    id: 4,
    action: 'assigned',
    user: { id: 1, name: 'أحمد' },
    old_values: null,
    new_values: { assigned_to: 2 },
    created_at: new Date(Date.now() - 172800000).toISOString(),
  },
  {
    id: 5,
    action: 'priority_changed',
    user: { id: 1, name: 'أحمد' },
    old_values: { priority: 'low' },
    new_values: { priority: 'high' },
    created_at: new Date(Date.now() - 259200000).toISOString(),
  },
  {
    id: 6,
    action: 'status_changed',
    user: { id: 2, name: 'محمد' },
    old_values: { status: 'in_progress' },
    new_values: { status: 'completed' },
    created_at: new Date(Date.now() - 604800000).toISOString(),
  },
];

describe('TaskActivityLog Basic', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('shows loading state initially', () => {
    (tasksApi.getActivityLog as any).mockImplementation(() => new Promise(() => {}));
    render(<TaskActivityLog taskId={1} />);
    expect(screen.getByTestId('loader-icon')).toBeInTheDocument();
  });

  it('calls API with task ID', async () => {
    render(<TaskActivityLog taskId={5} />);
    await waitFor(() => {
      expect(tasksApi.getActivityLog).toHaveBeenCalledWith(5, 50);
    });
  });

  it('shows header by default', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('سجل النشاطات')).toBeInTheDocument();
    });
  });

  it('shows activities count', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('6')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Empty State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue([]);
  });

  it('shows empty message', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('لا توجد نشاطات بعد')).toBeInTheDocument();
    });
  });

  it('shows activity icon in empty state', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByTestId('activity-icon')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Activity Items', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('shows user names', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getAllByText('أحمد').length).toBeGreaterThan(0);
    });
  });

  it('shows created action description', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('أنشأ المهمة')).toBeInTheDocument();
    });
  });

  it('shows updated action description', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('عدّل المهمة')).toBeInTheDocument();
    });
  });

  it('shows comment added description', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('أضاف تعليقاً')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Max Items', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('limits displayed items by default', async () => {
    render(<TaskActivityLog taskId={1} maxItems={3} />);
    await waitFor(() => {
      expect(screen.getByText('أنشأ المهمة')).toBeInTheDocument();
    });
    // يجب أن يظهر زر "عرض المزيد"
    expect(screen.getByText(/عرض المزيد/)).toBeInTheDocument();
  });

  it('shows "show more" button when has more', async () => {
    render(<TaskActivityLog taskId={1} maxItems={3} />);
    await waitFor(() => {
      expect(screen.getByText(/عرض المزيد \(3\)/)).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Expand/Collapse', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('expands on click', async () => {
    render(<TaskActivityLog taskId={1} maxItems={3} />);
    await waitFor(() => {
      expect(screen.getByText(/عرض المزيد/)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/عرض المزيد/));
    await waitFor(() => {
      expect(screen.getByText('عرض أقل')).toBeInTheDocument();
    });
  });

  it('shows chevron down icon initially', async () => {
    render(<TaskActivityLog taskId={1} maxItems={3} />);
    await waitFor(() => {
      expect(screen.getByTestId('chevron-down-icon')).toBeInTheDocument();
    });
  });

  it('shows chevron up icon when expanded', async () => {
    render(<TaskActivityLog taskId={1} maxItems={3} />);
    await waitFor(() => {
      expect(screen.getByText(/عرض المزيد/)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/عرض المزيد/));
    await waitFor(() => {
      expect(screen.getByTestId('chevron-up-icon')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Header Toggle', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('hides header when showHeader is false', async () => {
    render(<TaskActivityLog taskId={1} showHeader={false} />);
    await waitFor(() => {
      expect(screen.queryByText('سجل النشاطات')).not.toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Compact Mode', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(mockActivities);
  });

  it('renders in compact mode', async () => {
    render(<TaskActivityLog taskId={1} compact={true} />);
    await waitFor(() => {
      expect(screen.getByText('أنشأ المهمة')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Status Changes', () => {
  const statusChangeActivities = [
    {
      id: 1,
      action: 'updated',
      user: { id: 1, name: 'أحمد' },
      old_values: { status: 'todo' },
      new_values: { status: 'in_progress' },
      created_at: new Date().toISOString(),
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(statusChangeActivities);
  });

  it('shows status change details', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('الحالة:')).toBeInTheDocument();
    });
  });

  it('shows old status value', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
    });
  });

  it('shows new status value', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Priority Changes', () => {
  const priorityChangeActivities = [
    {
      id: 1,
      action: 'updated',
      user: { id: 1, name: 'أحمد' },
      old_values: { priority: 'low' },
      new_values: { priority: 'high' },
      created_at: new Date().toISOString(),
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(priorityChangeActivities);
  });

  it('shows priority change details', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('الأولوية:')).toBeInTheDocument();
    });
  });

  it('shows old priority value', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('منخفضة')).toBeInTheDocument();
    });
  });

  it('shows new priority value', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('عالية')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog System User', () => {
  const systemActivities = [
    {
      id: 1,
      action: 'created',
      user: null,
      old_values: null,
      new_values: null,
      created_at: new Date().toISOString(),
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(systemActivities);
  });

  it('shows system as user name', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('النظام')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Comment With Attachments', () => {
  const commentWithAttachments = [
    {
      id: 1,
      action: 'comment_added',
      user: { id: 1, name: 'أحمد' },
      old_values: null,
      new_values: { attachments_count: 2 },
      created_at: new Date().toISOString(),
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(commentWithAttachments);
  });

  it('shows attachment count in comment', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('أضاف تعليقاً مع 2 مرفق')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog Subtask Actions', () => {
  const subtaskActivities = [
    {
      id: 1,
      action: 'subtask_created',
      user: { id: 1, name: 'أحمد' },
      old_values: null,
      new_values: { subtask_title: 'مهمة فرعية جديدة' },
      created_at: new Date().toISOString(),
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue(subtaskActivities);
  });

  it('shows subtask created description', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('أضاف مهمة فرعية: مهمة فرعية جديدة')).toBeInTheDocument();
    });
  });
});

describe('TaskActivityLog API Error', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    (tasksApi.getActivityLog as any).mockRejectedValue(new Error('API Error'));
    consoleSpy.mockRestore();
  });

  it('handles error gracefully', async () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('لا توجد نشاطات بعد')).toBeInTheDocument();
    });
    consoleSpy.mockRestore();
  });
});

describe('TaskActivityLog Relative Time', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue([
      {
        id: 1,
        action: 'created',
        user: { id: 1, name: 'أحمد' },
        old_values: null,
        new_values: null,
        created_at: new Date().toISOString(), // الآن
      },
    ]);
  });

  it('shows relative time for recent activity', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('الآن')).toBeInTheDocument();
    });
  });
});

// Regression: the unified `/unified-tasks/{id}/activity-log` endpoint returns
// full ActivityLog models with an eager-loaded `user` (whole User object) plus
// extra fields (`description`, `loggable_type`, `user_id`, ...). The component
// must keep reading `user.name` and the change values without breaking on the
// extra fields.
describe('TaskActivityLog Unified Shape', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (tasksApi.getActivityLog as any).mockResolvedValue([
      {
        id: 101,
        action: 'updated',
        description: 'Task updated',
        loggable_type: 'App\\Modules\\Tasks\\Models\\Task',
        loggable_id: 1,
        old_values: { status: 'todo' },
        new_values: { status: 'in_progress' },
        user_id: 7,
        ip_address: '127.0.0.1',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        user: {
          id: 7,
          name: 'سارة',
          email: 'sara@example.com',
          organization_id: 1,
          created_at: new Date().toISOString(),
        },
      },
    ]);
  });

  it('renders user name from the full user object', async () => {
    render(<TaskActivityLog taskId={1} />);
    await waitFor(() => {
      expect(screen.getByText('سارة')).toBeInTheDocument();
    });
  });

  it('renders the status change from old/new values', async () => {
    render(<TaskActivityLog taskId={1} showHeader={false} />);
    await waitFor(() => {
      // status value -> human label mapping inside the component
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });
  });
});
