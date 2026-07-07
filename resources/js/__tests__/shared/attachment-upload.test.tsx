import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconX: () => <span data-testid="x-icon">X</span>,
  IconUpload: () => <span data-testid="upload-icon">Upload</span>,
  IconFileText: () => <span data-testid="file-icon">FileText</span>,
  IconPhoto: () => <span data-testid="image-icon">Image</span>,
  IconEye: () => <span data-testid="eye-icon">Eye</span>,

  };
});

import AttachmentUpload from '@features/project-expenses/ui/expenses/AttachmentUpload';

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
};

describe('AttachmentUpload Empty State', () => {
  const defaultProps = {
    projectId: 5,
    attachmentFile: null,
    attachmentPreview: null,
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders upload area when no file', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText('اضغط لرفع ملف أو اسحبه هنا')).toBeInTheDocument();
  });

  it('renders file type hint', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText(/PDF, JPG, PNG/)).toBeInTheDocument();
  });

  it('renders size limit hint', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText(/5MB/)).toBeInTheDocument();
  });

  it('renders upload icon', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('upload-icon')).toBeInTheDocument();
  });

  it('renders hidden file input', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const input = document.querySelector('input[type="file"]');
    expect(input).toBeInTheDocument();
    expect(input).toHaveClass('hidden');
  });

  it('has correct accept attribute', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const input = document.querySelector('input[type="file"]');
    expect(input).toHaveAttribute('accept', '.pdf,.jpg,.jpeg,.png');
  });

  it('calls onFileChange when file selected', () => {
    const onFileChange = vi.fn();
    render(<AttachmentUpload {...defaultProps} onFileChange={onFileChange} />);
    const input = document.querySelector('input[type="file"]');
    if (input) {
      fireEvent.change(input, { target: { files: [new File([''], 'test.pdf')] } });
      expect(onFileChange).toHaveBeenCalled();
    }
  });
});

describe('AttachmentUpload With File', () => {
  const mockFile = new File(['test content'], 'test.pdf', { type: 'application/pdf' });

  const defaultProps = {
    projectId: 5,
    attachmentFile: mockFile,
    attachmentPreview: null,
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders file info when file attached', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText('test.pdf')).toBeInTheDocument();
  });

  it('renders file size', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText(/KB/)).toBeInTheDocument();
  });

  it('renders PDF icon for PDF files', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('file-icon')).toBeInTheDocument();
  });

  it('renders remove button', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTitle('إزالة الملف')).toBeInTheDocument();
  });

  it('calls onRemove when remove clicked', () => {
    const onRemove = vi.fn();
    render(<AttachmentUpload {...defaultProps} onRemove={onRemove} />);
    fireEvent.click(screen.getByTitle('إزالة الملف'));
    expect(onRemove).toHaveBeenCalled();
  });
});

describe('AttachmentUpload With Image Preview', () => {
  const mockFile = new File(['test content'], 'test.jpg', { type: 'image/jpeg' });

  const defaultProps = {
    projectId: 5,
    attachmentFile: mockFile,
    attachmentPreview: 'data:image/jpeg;base64,test',
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders image preview when available', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const img = document.querySelector('img');
    expect(img).toBeInTheDocument();
    expect(img).toHaveAttribute('src', 'data:image/jpeg;base64,test');
  });

  it('renders preview with correct alt', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const img = document.querySelector('img');
    expect(img).toHaveAttribute('alt', 'معاينة');
  });
});

describe('AttachmentUpload With Existing Expense Attachment', () => {
  const defaultProps = {
    projectId: 5,
    attachmentFile: null,
    attachmentPreview: null,
    expense: mockExpense,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders existing attachment info', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText('receipt.pdf')).toBeInTheDocument();
  });

  it('renders "attached file" text', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText('ملف مرفق')).toBeInTheDocument();
  });

  it('renders view button for existing attachment', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTitle('عرض الملف')).toBeInTheDocument();
  });

  it('renders view link with correct href', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const viewLink = screen.getByTitle('عرض الملف');
    // Attachments are served through the authenticated API endpoint, not public /storage.
    expect(viewLink).toHaveAttribute('href', '/api/projects/5/expenses/1/attachment');
  });

  it('opens in new tab', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const viewLink = screen.getByTitle('عرض الملف');
    expect(viewLink).toHaveAttribute('target', '_blank');
    expect(viewLink).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('renders eye icon', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('eye-icon')).toBeInTheDocument();
  });
});

describe('AttachmentUpload PDF Expense Attachment', () => {
  const pdfExpense = {
    ...mockExpense,
    attachment_path: 'attachments/document.pdf',
  };

  const defaultProps = {
    projectId: 5,
    attachmentFile: null,
    attachmentPreview: null,
    expense: pdfExpense,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders PDF icon for PDF attachments', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('file-icon')).toBeInTheDocument();
  });
});

describe('AttachmentUpload Image Expense Attachment', () => {
  const imageExpense = {
    ...mockExpense,
    attachment_path: 'attachments/receipt.jpg',
  };

  const defaultProps = {
    projectId: 5,
    attachmentFile: null,
    attachmentPreview: null,
    expense: imageExpense,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders image preview for image attachments', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const img = document.querySelector('img');
    expect(img).toBeInTheDocument();
    // Image attachments load via the authenticated API endpoint, not public /storage.
    expect(img).toHaveAttribute('src', '/api/projects/5/expenses/1/attachment');
  });
});

describe('AttachmentUpload Image File Without Preview', () => {
  const mockFile = new File(['test content'], 'test.png', { type: 'image/png' });

  const defaultProps = {
    projectId: 5,
    attachmentFile: mockFile,
    attachmentPreview: null,
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders image icon when no preview', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('image-icon')).toBeInTheDocument();
  });
});

describe('AttachmentUpload Remove Button', () => {
  const mockFile = new File(['test content'], 'test.pdf', { type: 'application/pdf' });

  const defaultProps = {
    projectId: 5,
    attachmentFile: mockFile,
    attachmentPreview: null,
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders X icon in remove button', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByTestId('x-icon')).toBeInTheDocument();
  });

  it('remove button has type button', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const removeButton = screen.getByTitle('إزالة الملف');
    expect(removeButton).toHaveAttribute('type', 'button');
  });
});

describe('AttachmentUpload With New File Over Existing', () => {
  const mockFile = new File(['new content'], 'new-file.pdf', { type: 'application/pdf' });

  const defaultProps = {
    projectId: 5,
    attachmentFile: mockFile,
    attachmentPreview: null,
    expense: mockExpense,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows new file name instead of existing', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.getByText('new-file.pdf')).toBeInTheDocument();
    expect(screen.queryByText('receipt.pdf')).not.toBeInTheDocument();
  });

  it('does not show view button for new file', () => {
    render(<AttachmentUpload {...defaultProps} />);
    expect(screen.queryByTitle('عرض الملف')).not.toBeInTheDocument();
  });
});

describe('AttachmentUpload Styling', () => {
  const defaultProps = {
    projectId: 5,
    attachmentFile: null,
    attachmentPreview: null,
    expense: null,
    onFileChange: vi.fn(),
    onRemove: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders upload label with dashed border', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const label = document.querySelector('label');
    expect(label).toHaveClass('border-dashed');
  });

  it('renders upload label as cursor pointer', () => {
    render(<AttachmentUpload {...defaultProps} />);
    const label = document.querySelector('label');
    expect(label).toHaveClass('cursor-pointer');
  });
});
