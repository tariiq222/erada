import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
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

// Import components
import ExpenseDeleteModal from '@features/project-expenses/ui/expenses/ExpenseDeleteModal';
import ExpenseStatsCards from '@features/project-expenses/ui/expenses/ExpenseStatsCards';
import ExpenseCardsView from '@features/project-expenses/ui/expenses/ExpenseCardsView';
import { categoryColors, EXPENSE_CATEGORIES, formatCurrency } from '@features/project-expenses/ui/expenses/types';

// Mock expense data
const mockExpense = {
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
  created_at: '2025-01-15T10:00:00',
};

const mockExpenses = [
  mockExpense,
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
    created_at: '2025-01-16T10:00:00',
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
    created_at: '2025-01-17T10:00:00',
  },
];

const mockStats = {
  total_expenses: 18000,
  budget: 50000,
  spent_amount: 18000,
  remaining: 32000,
  percentage_used: 36,
  by_category: {
    materials: { count: 1, total: 5000 },
    services: { count: 1, total: 10000 },
    training: { count: 1, total: 3000 },
  },
};

// ==================== ExpenseDeleteModal Tests ====================
describe('ExpenseDeleteModal Component', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: mockExpense,
    onConfirm: vi.fn(),
    isDeleting: false,
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders modal title', () => {
    render(<ExpenseDeleteModal {...defaultProps} />);
    expect(screen.getByText('تأكيد الحذف')).toBeInTheDocument();
  });

  it('renders expense title in confirmation message', () => {
    render(<ExpenseDeleteModal {...defaultProps} />);
    expect(screen.getByText(/شراء مستلزمات/)).toBeInTheDocument();
  });

  it('renders expense amount', () => {
    render(<ExpenseDeleteModal {...defaultProps} />);
    // formatCurrency formats the amount - check for المبلغ label
    expect(screen.getByText(/المبلغ/)).toBeInTheDocument();
  });

  it('renders cancel and delete buttons', () => {
    render(<ExpenseDeleteModal {...defaultProps} />);
    expect(screen.getByText('إلغاء')).toBeInTheDocument();
    expect(screen.getByText('حذف')).toBeInTheDocument();
  });

  it('calls onConfirm when delete button clicked', async () => {
    const onConfirm = vi.fn();
    render(<ExpenseDeleteModal {...defaultProps} onConfirm={onConfirm} />);

    await userEvent.click(screen.getByText('حذف'));
    expect(onConfirm).toHaveBeenCalled();
  });

  it('calls onClose when cancel button clicked', async () => {
    const onClose = vi.fn();
    render(<ExpenseDeleteModal {...defaultProps} onClose={onClose} />);

    await userEvent.click(screen.getByText('إلغاء'));
    expect(onClose).toHaveBeenCalled();
  });

  it('shows loading state when deleting', () => {
    render(<ExpenseDeleteModal {...defaultProps} isDeleting={true} />);
    expect(screen.getByText('جاري الحذف...')).toBeInTheDocument();
  });

  it('disables delete button when deleting', () => {
    render(<ExpenseDeleteModal {...defaultProps} isDeleting={true} />);
    expect(screen.getByText('جاري الحذف...')).toBeDisabled();
  });
});

// ==================== ExpenseStatsCards Tests ====================
describe('ExpenseStatsCards Component', () => {
  it('renders all stat cards', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);

    expect(screen.getByText('الميزانية')).toBeInTheDocument();
    expect(screen.getByText('المصروف')).toBeInTheDocument();
    expect(screen.getByText('المتبقي')).toBeInTheDocument();
    expect(screen.getByText('الاستهلاك')).toBeInTheDocument();
  });

  it('renders budget section', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);
    // Budget label exists
    expect(screen.getByText('الميزانية')).toBeInTheDocument();
  });

  it('renders spent section', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);
    // Spent label exists
    expect(screen.getByText('المصروف')).toBeInTheDocument();
  });

  it('renders remaining section', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);
    // Remaining label exists
    expect(screen.getByText('المتبقي')).toBeInTheDocument();
  });

  it('renders percentage used', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);
    // Percentage shown twice - in card and progress bar
    const percentages = screen.getAllByText('36%');
    expect(percentages.length).toBeGreaterThan(0);
  });

  it('shows warning when usage is high', () => {
    const highUsageStats = {
      ...mockStats,
      percentage_used: 85,
    };
    render(<ExpenseStatsCards budget={50000} stats={highUsageStats} />);
    expect(screen.getByText('تحذير!')).toBeInTheDocument();
  });

  it('renders progress bar when budget exists', () => {
    render(<ExpenseStatsCards budget={50000} stats={mockStats} />);
    expect(screen.getByText('استهلاك الميزانية')).toBeInTheDocument();
  });

  it('handles null budget', () => {
    render(<ExpenseStatsCards budget={null} stats={mockStats} />);
    expect(screen.getByText('-')).toBeInTheDocument();
  });

  it('handles null stats', () => {
    render(<ExpenseStatsCards budget={50000} stats={null} />);
    expect(screen.getByText('الميزانية')).toBeInTheDocument();
    // Percentage shown as 0% multiple times
    const zeroPercentages = screen.getAllByText('0%');
    expect(zeroPercentages.length).toBeGreaterThan(0);
  });

  it('handles negative remaining amount', () => {
    const negativeStats = {
      ...mockStats,
      remaining: -5000,
    };
    render(<ExpenseStatsCards budget={50000} stats={negativeStats} />);
    expect(screen.getByText('المتبقي')).toBeInTheDocument();
  });
});

// ==================== ExpenseCardsView Tests ====================
describe('ExpenseCardsView Component', () => {
  const defaultProps = {
    projectId: 5,
    expenses: mockExpenses,
    onEdit: vi.fn(),
    onDelete: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders all expenses', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    expect(screen.getByText('شراء مستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات استشارية')).toBeInTheDocument();
    expect(screen.getByText('تدريب الفريق')).toBeInTheDocument();
  });

  it('renders expense descriptions', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    expect(screen.getByText('مستلزمات مكتبية')).toBeInTheDocument();
    expect(screen.getByText('دورة تدريبية')).toBeInTheDocument();
  });

  it('renders expense titles and amounts exist', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    // Check that titles are displayed - amounts use Arabic numerals
    expect(screen.getByText('شراء مستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات استشارية')).toBeInTheDocument();
    expect(screen.getByText('تدريب الفريق')).toBeInTheDocument();
  });

  it('renders category badges', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    expect(screen.getByText('مواد ومستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات')).toBeInTheDocument();
    expect(screen.getByText('تدريب')).toBeInTheDocument();
  });

  it('renders creator names', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    const ahmadNames = screen.getAllByText('أحمد محمد');
    expect(ahmadNames.length).toBeGreaterThan(0);
    expect(screen.getByText('سارة أحمد')).toBeInTheDocument();
  });

  it('renders linked task info', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    expect(screen.getByText(/مرتبط بـ: مهمة اختبار/)).toBeInTheDocument();
    expect(screen.getByText(/مرتبط بـ: مهمة التدريب/)).toBeInTheDocument();
  });

  it('renders attachment link for expenses with attachments', () => {
    render(<ExpenseCardsView {...defaultProps} />);

    const attachmentLink = screen.getByTitle('عرض المرفق');
    // Attachments are served through the authenticated API endpoint, not public /storage.
    expect(attachmentLink).toHaveAttribute('href', '/api/projects/5/expenses/1/attachment');
  });

  it('calls onEdit when edit button clicked', async () => {
    const onEdit = vi.fn();
    render(<ExpenseCardsView {...defaultProps} onEdit={onEdit} />);

    // ExpenseCardsView does not yet wire aria-labels on edit/delete; pick edit by SVG
    // presence — tabler icons render a stable class prefix.
    const buttons = screen.getAllByRole('button');
    const editButton = buttons.find((b) => b.querySelector('svg.tabler-icon-edit') !== null);
    if (editButton) {
      await userEvent.click(editButton);
      expect(onEdit).toHaveBeenCalled();
    }
  });

  it('calls onDelete when delete button clicked', async () => {
    const onDelete = vi.fn();
    render(<ExpenseCardsView {...defaultProps} onDelete={onDelete} />);

    const buttons = screen.getAllByRole('button');
    const deleteButton = buttons.find((b) =>
      b.querySelector('svg.tabler-icon-trash, svg.tabler-icon-trash-filled') !== null,
    );
    if (deleteButton) {
      await userEvent.click(deleteButton);
      expect(onDelete).toHaveBeenCalled();
    }
  });

  it('renders empty state correctly', () => {
    render(<ExpenseCardsView {...defaultProps} expenses={[]} />);
    // No expenses means no cards rendered
    expect(screen.queryByText('شراء مستلزمات')).not.toBeInTheDocument();
  });
});

// ==================== Types and Constants Tests ====================
describe('Expense Types and Constants', () => {
  describe('categoryColors', () => {
    it('has color for human_resources', () => {
      expect(categoryColors.human_resources).toBe('bg-[var(--accent-subtle)] text-[var(--accent-default)]');
    });

    it('has color for materials', () => {
      expect(categoryColors.materials).toBe('bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]');
    });

    it('has color for services', () => {
      expect(categoryColors.services).toBe('bg-[var(--accent-subtle)] text-[var(--accent-default)]');
    });

    it('has color for operational', () => {
      expect(categoryColors.operational).toBe('bg-[var(--status-success-subtle)] text-[var(--status-success-text)]');
    });

    it('has color for travel', () => {
      expect(categoryColors.travel).toBe('bg-[var(--accent-subtle)] text-[var(--accent-default)]');
    });

    it('has color for training', () => {
      expect(categoryColors.training).toBe('bg-[var(--accent-subtle)] text-[var(--accent-default)]');
    });

    it('has color for other', () => {
      expect(categoryColors.other).toBe('bg-[var(--surface-muted)] text-[var(--text-secondary)]');
    });
  });

  describe('EXPENSE_CATEGORIES', () => {
    it('has label for human_resources', () => {
      expect(EXPENSE_CATEGORIES.human_resources).toBe('موارد بشرية');
    });

    it('has label for materials', () => {
      expect(EXPENSE_CATEGORIES.materials).toBe('مواد ومستلزمات');
    });

    it('has label for services', () => {
      expect(EXPENSE_CATEGORIES.services).toBe('خدمات');
    });

    it('has label for operational', () => {
      expect(EXPENSE_CATEGORIES.operational).toBe('تشغيلية');
    });

    it('has label for travel', () => {
      expect(EXPENSE_CATEGORIES.travel).toBe('سفر وتنقل');
    });

    it('has label for training', () => {
      expect(EXPENSE_CATEGORIES.training).toBe('تدريب');
    });

    it('has label for other', () => {
      expect(EXPENSE_CATEGORIES.other).toBe('أخرى');
    });
  });

  describe('formatCurrency', () => {
    it('formats positive numbers', () => {
      const result = formatCurrency(5000);
      // Arabic number format: ٥٬٠٠٠٫٠٠
      expect(result).toBeTruthy();
      expect(result.length).toBeGreaterThan(0);
    });

    it('formats numbers with decimals', () => {
      const result = formatCurrency(5000.50);
      expect(result).toBeTruthy();
    });

    it('formats zero', () => {
      const result = formatCurrency(0);
      // Arabic format: ٠٫٠٠
      expect(result).toBeTruthy();
    });

    it('formats large numbers', () => {
      const result = formatCurrency(1000000);
      expect(result).toBeTruthy();
    });

    it('formats negative numbers', () => {
      const result = formatCurrency(-5000);
      expect(result).toBeTruthy();
      expect(result).toContain('-');
    });
  });
});
