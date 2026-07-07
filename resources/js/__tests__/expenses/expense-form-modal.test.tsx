import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock useToast
const mockShowToast = vi.fn();
vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: mockShowToast }),
}));

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconPaperclip: () => <span data-testid="paperclip-icon">Paperclip</span>,
  IconX: () => <span data-testid="x-icon">X</span>,
  IconUpload: () => <span data-testid="upload-icon">Upload</span>,
  IconFileText: () => <span data-testid="file-icon">FileText</span>,
  IconPhoto: () => <span data-testid="image-icon">Image</span>,
  IconEye: () => <span data-testid="eye-icon">Eye</span>,

  };
});

// Mock UI components
vi.mock('@shared/ui', async (importOriginal) => ({
  ...((await importOriginal()) as Record<string, unknown>),
  Button: ({ children, onClick, type, disabled, variant }: any) => (
    <button onClick={onClick} type={type} disabled={disabled} data-variant={variant}>
      {children}
    </button>
  ),
  Input: ({ value, onChange, placeholder, type, required, step, min }: any) => (
    <input
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      type={type || 'text'}
      required={required}
      step={step}
      min={min}
      data-testid="input"
    />
  ),
  Modal: ({ open, onClose, title, children, size }: any) =>
    open ? (
      <div data-testid="modal" data-size={size}>
        <h2>{title}</h2>
        <button onClick={onClose} data-testid="modal-close">إغلاق</button>
        {children}
      </div>
    ) : null,
  ModalBody: ({ children, className }: any) => <div className={className} data-testid="modal-body">{children}</div>,
  ModalFooter: ({ children }: any) => <div data-testid="modal-footer">{children}</div>,
  Select: ({ options, value, onChange, placeholder, required }: any) => (
    <select value={value} onChange={onChange} required={required} data-testid="select">
      {placeholder && <option value="">{placeholder}</option>}
      {options?.map((opt: any) => (
        <option key={opt.value} value={opt.value}>
          {opt.label}
        </option>
      ))}
    </select>
  ),
}));

// Mock AttachmentUpload
vi.mock('@features/project-expenses/ui/expenses/AttachmentUpload', () => ({
  default: ({ attachmentFile, onFileChange, onRemove }: any) => (
    <div data-testid="attachment-upload">
      <input
        type="file"
        data-testid="file-input"
        onChange={onFileChange}
      />
      {attachmentFile && (
        <button onClick={onRemove} data-testid="remove-attachment">
          إزالة
        </button>
      )}
    </div>
  ),
}));

import ExpenseFormModal from '@features/project-expenses/ui/expenses/ExpenseFormModal';

const mockExpense = {
  id: 1,
  title: 'شراء مستلزمات',
  description: 'مستلزمات مكتبية',
  amount: 5000,
  category: 'materials',
  expense_date: '2025-01-15',
  reference_number: 'EXP-001',
  attachment_path: null,
  task: { id: 1, title: 'مهمة اختبار' },
  creator: { id: 1, name: 'أحمد محمد' },
};

const mockTasks = [
  { id: 1, title: 'مهمة اختبار' },
  { id: 2, title: 'مهمة التدريب' },
];

describe('ExpenseFormModal Basic', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders modal when open', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByTestId('modal')).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(<ExpenseFormModal {...defaultProps} isOpen={false} />);
    expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
  });

  it('shows add title when no expense', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('إضافة مصروف جديد')).toBeInTheDocument();
  });

  it('shows edit title when expense exists', () => {
    render(<ExpenseFormModal {...defaultProps} expense={mockExpense} />);
    expect(screen.getByText('تعديل مصروف')).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Form Fields', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders title field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('العنوان')).toBeInTheDocument();
  });

  it('renders amount field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('المبلغ')).toBeInTheDocument();
  });

  it('renders category field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('التصنيف')).toBeInTheDocument();
  });

  it('renders date field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('التاريخ')).toBeInTheDocument();
  });

  it('renders description field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('الوصف')).toBeInTheDocument();
  });

  it('renders task link field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('ربط بمهمة')).toBeInTheDocument();
  });

  it('renders reference number field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('رقم مرجعي')).toBeInTheDocument();
  });

  it('renders attachment field label', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText(/مرفق/)).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Required Fields', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows required indicator for title', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const titleLabel = screen.getByText('العنوان').closest('label');
    expect(titleLabel?.querySelector('.text-\\[var\\(--status-danger\\)\\]')).toBeInTheDocument();
  });

  it('shows required indicator for amount', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const amountLabel = screen.getByText('المبلغ').closest('label');
    expect(amountLabel?.querySelector('.text-\\[var\\(--status-danger\\)\\]')).toBeInTheDocument();
  });

  it('shows required indicator for category', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const categoryLabel = screen.getByText('التصنيف').closest('label');
    expect(categoryLabel?.querySelector('.text-\\[var\\(--status-danger\\)\\]')).toBeInTheDocument();
  });

  it('shows required indicator for date', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const dateLabel = screen.getByText('التاريخ').closest('label');
    expect(dateLabel?.querySelector('.text-\\[var\\(--status-danger\\)\\]')).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Buttons', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders cancel button', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('إلغاء')).toBeInTheDocument();
  });

  it('renders add button when no expense', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('إضافة')).toBeInTheDocument();
  });

  it('renders update button when expense exists', () => {
    render(<ExpenseFormModal {...defaultProps} expense={mockExpense} />);
    expect(screen.getByText('تحديث')).toBeInTheDocument();
  });

  it('calls onClose when cancel clicked', async () => {
    const onClose = vi.fn();
    render(<ExpenseFormModal {...defaultProps} onClose={onClose} />);
    await userEvent.click(screen.getByText('إلغاء'));
    expect(onClose).toHaveBeenCalled();
  });
});

describe('ExpenseFormModal Edit Mode', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: mockExpense,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('populates form with expense data', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const inputs = screen.getAllByTestId('input');
    const titleInput = inputs.find(i => (i as HTMLInputElement).value === 'شراء مستلزمات');
    expect(titleInput).toBeInTheDocument();
  });

  it('shows expense amount in form', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const inputs = screen.getAllByTestId('input');
    const amountInput = inputs.find(i => (i as HTMLInputElement).value === '5000');
    expect(amountInput).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Category Options', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders category select', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const selects = screen.getAllByTestId('select');
    expect(selects.length).toBeGreaterThan(0);
  });

  it('renders category options', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByText('مواد ومستلزمات')).toBeInTheDocument();
    expect(screen.getByText('خدمات')).toBeInTheDocument();
    expect(screen.getByText('تدريب')).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Task Options', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders task options', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const noLinkOptions = screen.getAllByText('بدون ربط');
    expect(noLinkOptions.length).toBeGreaterThan(0);
    expect(screen.getByText('مهمة اختبار')).toBeInTheDocument();
    expect(screen.getByText('مهمة التدريب')).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Submit', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onSubmit on form submit', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<ExpenseFormModal {...defaultProps} onSubmit={onSubmit} />);

    // Fill required fields
    const inputs = screen.getAllByTestId('input');
    fireEvent.change(inputs[0], { target: { value: 'مصروف جديد' } });
    fireEvent.change(inputs[1], { target: { value: '1000' } });

    // Submit form
    const form = document.querySelector('form');
    if (form) {
      fireEvent.submit(form);
      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalled();
      });
    }
  });

  it('shows loading state when submitting', async () => {
    const onSubmit = vi.fn().mockImplementation(() => new Promise(() => {}));
    render(<ExpenseFormModal {...defaultProps} onSubmit={onSubmit} />);

    const form = document.querySelector('form');
    if (form) {
      fireEvent.submit(form);
      await waitFor(() => {
        expect(screen.getByText('جاري الحفظ...')).toBeInTheDocument();
      });
    }
  });

  it('closes modal after successful submit', async () => {
    const onClose = vi.fn();
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<ExpenseFormModal {...defaultProps} onClose={onClose} onSubmit={onSubmit} />);

    const form = document.querySelector('form');
    if (form) {
      fireEvent.submit(form);
      await waitFor(() => {
        expect(onClose).toHaveBeenCalled();
      });
    }
  });
});

describe('ExpenseFormModal Attachment Upload', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders attachment upload component', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByTestId('attachment-upload')).toBeInTheDocument();
  });

  it('renders file input', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    expect(screen.getByTestId('file-input')).toBeInTheDocument();
  });
});

describe('ExpenseFormModal Modal Close', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: mockTasks,
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onClose when modal close button clicked', async () => {
    const onClose = vi.fn();
    render(<ExpenseFormModal {...defaultProps} onClose={onClose} />);
    await userEvent.click(screen.getByTestId('modal-close'));
    expect(onClose).toHaveBeenCalled();
  });
});

describe('ExpenseFormModal Empty Tasks', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    expense: null,
    tasks: [],
    onSubmit: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders with empty tasks list', () => {
    render(<ExpenseFormModal {...defaultProps} />);
    const noLinkOptions = screen.getAllByText('بدون ربط');
    expect(noLinkOptions.length).toBeGreaterThan(0);
  });
});
