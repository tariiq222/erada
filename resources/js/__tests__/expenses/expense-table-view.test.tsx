import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconTrash: () => <span data-testid="trash-icon">Trash2</span>,
  IconEdit: () => <span data-testid="edit-icon">Edit</span>,
  IconCalendar: () => <span data-testid="calendar-icon">Calendar</span>,
  IconPaperclip: () => <span data-testid="paperclip-icon">Paperclip</span>,

  };
});

// Mock utils
vi.mock('@shared/lib/utils', () => ({
  formatDate: (date: string) => date,
  cn: (...args: any[]) => args.filter(Boolean).join(' '),
}));

// Mock UI components
vi.mock('@shared/ui', () => ({
  Card: ({ children }: any) => <div data-testid="card">{children}</div>,
  CardContent: ({ children, className }: any) => <div className={className}>{children}</div>,
  Badge: ({ children, className }: any) => <span className={className} data-testid="badge">{children}</span>,
  Table: ({ children }: any) => <table data-testid="table">{children}</table>,
  TableHeader: ({ children }: any) => <thead>{children}</thead>,
  TableBody: ({ children }: any) => <tbody>{children}</tbody>,
  TableHead: ({ children }: any) => <th>{children}</th>,
  TableRow: ({ children }: any) => <tr>{children}</tr>,
  TableCell: ({ children }: any) => <td>{children}</td>,
}));

import ExpenseTableView from '@features/project-expenses/ui/expenses/ExpenseTableView';

const mockExpenses = [
  {
    id: 1,
    title: 'شراء مستلزمات',
    description: 'مستلزمات مكتبية',
    amount: 5000,
    category: 'materials',
    expense_date: '2025-01-15',
    reference_number: 'EXP-001',
    attachment_path: 'attachments/receipt.pdf',
    task: { id: 1, title: 'مهمة اختبار' },
    creator: { id: 1, name: 'أحمد محمد' },
  },
  {
    id: 2,
    title: 'خدمات استشارية',
    description: null,
    amount: 10000,
    category: 'services',
    expense_date: '2025-01-16',
    reference_number: null,
    attachment_path: null,
    task: null,
    creator: { id: 2, name: 'سارة أحمد' },
  },
  {
    id: 3,
    title: 'تدريب الفريق',
    description: 'دورة تدريبية',
    amount: 3000,
    category: 'training',
    expense_date: '2025-01-17',
    reference_number: 'EXP-002',
    attachment_path: null,
    task: { id: 2, title: 'مهمة التدريب' },
    creator: { id: 1, name: 'أحمد محمد' },
  },
];

describe('ExpenseTableView Basic', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByTestId('table')).toBeInTheDocument();
  });

  it('renders card wrapper', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByTestId('card')).toBeInTheDocument();
  });

  it('renders table headers', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('العنوان')).toBeInTheDocument();
    expect(screen.getByText('التصنيف')).toBeInTheDocument();
    expect(screen.getByText('المبلغ')).toBeInTheDocument();
    expect(screen.getByText('التاريخ')).toBeInTheDocument();
    expect(screen.getByText('المهمة')).toBeInTheDocument();
    expect(screen.getByText('بواسطة')).toBeInTheDocument();
    expect(screen.getByText('إجراءات')).toBeInTheDocument();
  });
});

describe('ExpenseTableView Data Display', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders expense titles', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('شراء مستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات استشارية')).toBeInTheDocument();
    expect(screen.getByText('تدريب الفريق')).toBeInTheDocument();
  });

  it('renders expense descriptions', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('مستلزمات مكتبية')).toBeInTheDocument();
    expect(screen.getByText('دورة تدريبية')).toBeInTheDocument();
  });

  it('renders reference numbers', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('#EXP-001')).toBeInTheDocument();
    expect(screen.getByText('#EXP-002')).toBeInTheDocument();
  });

  it('renders creator names', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const ahmadNames = screen.getAllByText('أحمد محمد');
    expect(ahmadNames.length).toBeGreaterThan(0);
    expect(screen.getByText('سارة أحمد')).toBeInTheDocument();
  });

  it('renders expense dates', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('2025-01-15')).toBeInTheDocument();
    expect(screen.getByText('2025-01-16')).toBeInTheDocument();
    expect(screen.getByText('2025-01-17')).toBeInTheDocument();
  });
});

describe('ExpenseTableView Category Badges', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders category badges', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const badges = screen.getAllByTestId('badge');
    expect(badges.length).toBe(3);
  });

  it('renders category labels', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('مواد ومستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات')).toBeInTheDocument();
    expect(screen.getByText('تدريب')).toBeInTheDocument();
  });
});

describe('ExpenseTableView Task Links', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders linked task titles', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('مهمة اختبار')).toBeInTheDocument();
    expect(screen.getByText('مهمة التدريب')).toBeInTheDocument();
  });

  it('shows dash for expenses without task', () => {
    render(<ExpenseTableView {...defaultProps} />);
    expect(screen.getByText('-')).toBeInTheDocument();
  });
});

describe('ExpenseTableView Attachments', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders attachment icon for expenses with attachments', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const paperclipIcons = screen.getAllByTestId('paperclip-icon');
    expect(paperclipIcons.length).toBe(1);
  });

  it('renders attachment link with correct href', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const attachmentLink = screen.getByTitle('عرض المرفق');
    // Attachments are served through the authenticated API endpoint, not public /storage.
    expect(attachmentLink).toHaveAttribute('href', '/api/projects/5/expenses/1/attachment');
  });

  it('opens attachment in new tab', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const attachmentLink = screen.getByTitle('عرض المرفق');
    expect(attachmentLink).toHaveAttribute('target', '_blank');
    expect(attachmentLink).toHaveAttribute('rel', 'noopener noreferrer');
  });
});

describe('ExpenseTableView Actions', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders edit buttons', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const editButtons = screen.getAllByTitle('تعديل');
    expect(editButtons.length).toBe(3);
  });

  it('renders delete buttons', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const deleteButtons = screen.getAllByTitle('حذف');
    expect(deleteButtons.length).toBe(3);
  });

  it('calls onEdit when edit button clicked', () => {
    const onEdit = vi.fn();
    render(<ExpenseTableView {...defaultProps} onEdit={onEdit} />);
    const editButtons = screen.getAllByTitle('تعديل');
    fireEvent.click(editButtons[0]);
    expect(onEdit).toHaveBeenCalledWith(mockExpenses[0]);
  });

  it('calls onDelete when delete button clicked', () => {
    const onDelete = vi.fn();
    render(<ExpenseTableView {...defaultProps} onDelete={onDelete} />);
    const deleteButtons = screen.getAllByTitle('حذف');
    fireEvent.click(deleteButtons[0]);
    expect(onDelete).toHaveBeenCalledWith(mockExpenses[0]);
  });

  it('calls onEdit with correct expense', () => {
    const onEdit = vi.fn();
    render(<ExpenseTableView {...defaultProps} onEdit={onEdit} />);
    const editButtons = screen.getAllByTitle('تعديل');
    fireEvent.click(editButtons[1]);
    expect(onEdit).toHaveBeenCalledWith(mockExpenses[1]);
  });

  it('calls onDelete with correct expense', () => {
    const onDelete = vi.fn();
    render(<ExpenseTableView {...defaultProps} onDelete={onDelete} />);
    const deleteButtons = screen.getAllByTitle('حذف');
    fireEvent.click(deleteButtons[2]);
    expect(onDelete).toHaveBeenCalledWith(mockExpenses[2]);
  });
});

describe('ExpenseTableView Icons', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders calendar icons', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const calendarIcons = screen.getAllByTestId('calendar-icon');
    expect(calendarIcons.length).toBe(3);
  });

  it('renders edit icons', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const editIcons = screen.getAllByTestId('edit-icon');
    expect(editIcons.length).toBe(3);
  });

  it('renders trash icons', () => {
    render(<ExpenseTableView {...defaultProps} />);
    const trashIcons = screen.getAllByTestId('trash-icon');
    expect(trashIcons.length).toBe(3);
  });
});

describe('ExpenseTableView Empty State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders empty table', () => {
    render(<ExpenseTableView expenses={[]} onEdit={vi.fn()} onDelete={vi.fn()} />);
    expect(screen.getByTestId('table')).toBeInTheDocument();
    expect(screen.queryByText('شراء مستلزمات')).not.toBeInTheDocument();
  });

  it('still renders headers when empty', () => {
    render(<ExpenseTableView expenses={[]} onEdit={vi.fn()} onDelete={vi.fn()} />);
    expect(screen.getByText('العنوان')).toBeInTheDocument();
  });
});

describe('ExpenseTableView Unknown Category', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('handles unknown category', () => {
    const unknownCategoryExpense = [{
      id: 1,
      title: 'مصروف غير معروف',
      description: null,
      amount: 1000,
      category: 'unknown_category',
      expense_date: '2025-01-15',
      reference_number: null,
      attachment_path: null,
      task: null,
      creator: { id: 1, name: 'أحمد' },
    }];
    render(<ExpenseTableView expenses={unknownCategoryExpense as any} onEdit={vi.fn()} onDelete={vi.fn()} />);
    expect(screen.getByText('unknown_category')).toBeInTheDocument();
  });
});
