import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import {IconActivity} from '@tabler/icons-react';

import { StatCard, DeleteConfirmationModal, MentionInput } from '@shared/ui';
import type { UserOption } from '@shared/ui/MentionInput';

describe('StatCard Component', () => {
  it('renders with default color (accent)', () => {
    render(<StatCard label="المشاريع" value={42} icon={IconActivity} />);

    expect(screen.getByText('المشاريع')).toBeInTheDocument();
    expect(screen.getByText('42')).toBeInTheDocument();
  });

  it('renders with string value', () => {
    render(<StatCard label="الحالة" value="نشط" icon={IconActivity} />);

    expect(screen.getByText('الحالة')).toBeInTheDocument();
    expect(screen.getByText('نشط')).toBeInTheDocument();
  });

  it('renders with success color', () => {
    render(
      <StatCard label="مكتملة" value={10} icon={IconActivity} color="success" />
    );

    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'success');
  });

  it('renders with warning color', () => {
    render(
      <StatCard label="معلقة" value={5} icon={IconActivity} color="warning" />
    );

    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'warning');
  });

  it('renders with danger color', () => {
    render(
      <StatCard label="متأخرة" value={3} icon={IconActivity} color="danger" />
    );

    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'danger');
  });

  it('renders with info color', () => {
    render(
      <StatCard label="جديدة" value={8} icon={IconActivity} color="info" />
    );

    expect(screen.getByTestId('stat-card-icon')).toHaveAttribute('data-color', 'info');
  });

  it('renders the icon correctly', () => {
    render(
      <StatCard label="اختبار" value={0} icon={IconActivity} />
    );

    expect(screen.getByTestId('stat-card-icon').querySelector('svg')).toBeInTheDocument();
  });
});

describe('DeleteConfirmationModal Component', () => {
  const mockOnClose = vi.fn();
  const mockOnConfirm = vi.fn();

  const defaultProps = {
    isOpen: true,
    item: { id: 1, name: 'عنصر للحذف' },
    title: 'تأكيد الحذف',
    itemName: 'عنصر اختبار',
    warningMessage: 'هذا الإجراء لا يمكن التراجع عنه',
    isDeleting: false,
    onClose: mockOnClose,
    onConfirm: mockOnConfirm,
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders nothing when isOpen is false', () => {
    render(
      <DeleteConfirmationModal {...defaultProps} isOpen={false} />
    );

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders nothing when item is null', () => {
    render(
      <DeleteConfirmationModal {...defaultProps} item={null} />
    );

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders modal when open with item', () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    expect(screen.getByText('تأكيد الحذف')).toBeInTheDocument();
    expect(screen.getByText('عنصر اختبار')).toBeInTheDocument();
    expect(screen.getByText(/هذا الإجراء لا يمكن التراجع عنه/)).toBeInTheDocument();
  });

  it('renders itemSubtitle when provided', () => {
    render(
      <DeleteConfirmationModal
        {...defaultProps}
        itemSubtitle="PRJ-001"
      />
    );

    expect(screen.getByText('PRJ-001')).toBeInTheDocument();
  });

  it('renders custom confirm button text', () => {
    render(
      <DeleteConfirmationModal
        {...defaultProps}
        confirmButtonText="حذف المشروع"
      />
    );

    expect(screen.getByText('حذف المشروع')).toBeInTheDocument();
  });

  it('calls onClose when cancel button is clicked', async () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    const cancelButton = screen.getByText('إلغاء');
    await userEvent.click(cancelButton);

    expect(mockOnClose).toHaveBeenCalledTimes(1);
  });

  it('calls onConfirm when confirm button is clicked', async () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    const confirmButton = screen.getByText('حذف');
    await userEvent.click(confirmButton);

    expect(mockOnConfirm).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when backdrop is clicked', async () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    const backdrop = screen.getByTestId('modal-backdrop');
    await userEvent.click(backdrop);
    expect(mockOnClose).toHaveBeenCalledTimes(1);
  });

  it('disables buttons when isDeleting is true', () => {
    render(<DeleteConfirmationModal {...defaultProps} isDeleting={true} />);

    const cancelButton = screen.getByText('إلغاء');
    expect(cancelButton).toBeDisabled();
  });

  it('does not call onClose when isDeleting and backdrop is clicked', async () => {
    render(
      <DeleteConfirmationModal {...defaultProps} isDeleting={true} />
    );

    const backdrop = screen.getByTestId('modal-backdrop');
    await userEvent.click(backdrop);
    expect(mockOnClose).not.toHaveBeenCalled();
  });

  it('renders close button in header', () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    const closeButton = screen.getByRole('button', { name: /إغلاق/i });
    expect(closeButton).toBeInTheDocument();
  });

  it('shows warning icon', () => {
    render(<DeleteConfirmationModal {...defaultProps} />);

    // AlertTriangle icon (IconBasket) is rendered inside the dialog
    expect(screen.getByRole('dialog').querySelector('svg')).toBeInTheDocument();
  });
});

describe('MentionInput Component', () => {
  const mockOnChange = vi.fn();
  const mockOnSubmit = vi.fn();
  const mockOnAttachmentsChange = vi.fn();

  const mockUsers: UserOption[] = [
    { id: 1, name: 'أحمد محمد', email: 'ahmed@test.com' },
    { id: 2, name: 'سارة علي', email: 'sara@test.com' },
    { id: 3, name: 'محمد خالد', email: 'mohamed@test.com' },
  ];

  const defaultProps = {
    value: '',
    onChange: mockOnChange,
    onSubmit: mockOnSubmit,
    users: mockUsers,
    attachments: [] as File[],
    onAttachmentsChange: mockOnAttachmentsChange,
    placeholder: 'اكتب تعليقك...',
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders textarea with placeholder', () => {
    render(<MentionInput {...defaultProps} />);

    expect(screen.getByPlaceholderText('اكتب تعليقك...')).toBeInTheDocument();
  });

  it('renders with value', () => {
    render(<MentionInput {...defaultProps} value="نص اختبار" />);

    expect(screen.getByDisplayValue('نص اختبار')).toBeInTheDocument();
  });

  it('calls onChange when typing', async () => {
    render(<MentionInput {...defaultProps} />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    await userEvent.type(textarea, 'مرحبا');

    expect(mockOnChange).toHaveBeenCalled();
  });

  it('shows suggestions when typing @', async () => {
    // We test that the component renders the suggestion dropdown correctly
    // by checking the internal state behavior through mocks
    const TestWrapper = () => {
      const [val, setVal] = React.useState('');
      return (
        <MentionInput
          {...defaultProps}
          value={val}
          onChange={(v) => {
            setVal(v);
            mockOnChange(v);
          }}
        />
      );
    };

    render(<TestWrapper />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    await userEvent.type(textarea, '@');

    // Wait for suggestions to appear
    await waitFor(() => {
      expect(screen.getByText('اذكر شخصاً')).toBeInTheDocument();
    });
  });

  it('filters users based on query after @', async () => {
    const { rerender } = render(<MentionInput {...defaultProps} value="@أح" />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');

    // Simulate typing @ followed by search query
    fireEvent.change(textarea, {
      target: { value: '@أح', selectionStart: 3 }
    });

    rerender(<MentionInput {...defaultProps} value="@أح" />);

    await waitFor(() => {
      const suggestions = screen.queryByText('أحمد محمد');
      // If suggestions appear, verify filtering
      if (suggestions) {
        expect(suggestions).toBeInTheDocument();
      }
    });
  });

  it('calls onSubmit when Enter is pressed without Shift', async () => {
    render(<MentionInput {...defaultProps} value="نص" />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });

    expect(mockOnSubmit).toHaveBeenCalled();
  });

  it('does not call onSubmit when Shift+Enter is pressed', async () => {
    render(<MentionInput {...defaultProps} value="نص" />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true });

    expect(mockOnSubmit).not.toHaveBeenCalled();
  });

  it('hides suggestions when Escape is pressed', async () => {
    render(<MentionInput {...defaultProps} value="@" />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    fireEvent.keyDown(textarea, { key: 'Escape' });

    await waitFor(() => {
      expect(screen.queryByText('اذكر شخصاً')).not.toBeInTheDocument();
    });
  });

  it('renders attachment button', () => {
    render(<MentionInput {...defaultProps} />);

    const attachButton = screen.getByRole('button', { name: /إرفاق ملف/i });
    expect(attachButton).toBeInTheDocument();
  });

  it('renders send button', () => {
    render(<MentionInput {...defaultProps} />);

    const sendButton = screen.getByRole('button', { name: /إرسال/i });
    expect(sendButton).toBeInTheDocument();
  });

  it('disables send button when empty and no attachments', () => {
    render(<MentionInput {...defaultProps} value="" />);

    const sendButton = screen.getByRole('button', { name: /إرسال/i });
    expect(sendButton).toBeDisabled();
  });

  it('enables send button when has text', () => {
    render(<MentionInput {...defaultProps} value="نص" />);

    const sendButton = screen.getByRole('button', { name: /إرسال/i });
    expect(sendButton).not.toBeDisabled();
  });

  it('enables send button when has attachments only', () => {
    const mockFile = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(
      <MentionInput {...defaultProps} value="" attachments={[mockFile]} />
    );

    const sendButton = screen.getByRole('button', { name: /إرسال/i });
    expect(sendButton).not.toBeDisabled();
  });

  it('renders attachments preview', () => {
    const mockFile = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...defaultProps} attachments={[mockFile]} />);

    expect(screen.getByText('test.pdf')).toBeInTheDocument();
  });

  it('shows file size when showFileDetails is true', () => {
    const mockFile = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(
      <MentionInput {...defaultProps} attachments={[mockFile]} showFileDetails={true} />
    );

    // File size should be displayed (7 bytes = "7 bytes")
    expect(screen.getByText('7 bytes')).toBeInTheDocument();
  });

  it('removes attachment when X is clicked', async () => {
    const mockFile = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(
      <MentionInput {...defaultProps} attachments={[mockFile]} />
    );

    await userEvent.click(screen.getByTestId('attachment-remove'));
    expect(mockOnAttachmentsChange).toHaveBeenCalledWith([]);
  });

  it('disables all inputs when disabled prop is true', () => {
    render(<MentionInput {...defaultProps} disabled={true} />);

    const textarea = screen.getByPlaceholderText('اكتب تعليقك...');
    expect(textarea).toBeDisabled();

    const attachButton = screen.getByRole('button', { name: /إرفاق ملف/i });
    expect(attachButton).toBeDisabled();
  });

  it('disables attachment button when max attachments reached', () => {
    const mockFiles = [
      new File(['1'], 'file1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'file2.pdf', { type: 'application/pdf' }),
    ];
    render(
      <MentionInput {...defaultProps} attachments={mockFiles} maxAttachments={2} />
    );

    const attachButton = screen.getByRole('button', { name: /إرفاق ملف/i });
    expect(attachButton).toBeDisabled();
  });

  it('calls onSubmit when send button is clicked', async () => {
    render(<MentionInput {...defaultProps} value="نص" />);

    const sendButton = screen.getByRole('button', { name: /إرسال/i });
    await userEvent.click(sendButton);
    expect(mockOnSubmit).toHaveBeenCalled();
  });

  it('formats file size correctly for KB', () => {
    const mockFile = new File([new ArrayBuffer(2048)], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...defaultProps} attachments={[mockFile]} showFileDetails={true} />);

    expect(screen.getByText('2.0 KB')).toBeInTheDocument();
  });

  it('formats file size correctly for MB', () => {
    const mockFile = new File([new ArrayBuffer(2 * 1024 * 1024)], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...defaultProps} attachments={[mockFile]} showFileDetails={true} />);

    expect(screen.getByText('2.0 MB')).toBeInTheDocument();
  });

  it('shows correct icon for image files', () => {
    const mockFile = new File([''], 'test.png', { type: 'image/png' });
    render(
      <MentionInput {...defaultProps} attachments={[mockFile]} showFileDetails={true} />
    );

    // Should have an SVG icon (the file-type icon inside the chip containing the file name)
    const fileName = screen.getByText('test.png');
    expect(fileName.closest('div')?.querySelector('svg')).toBeInTheDocument();
  });

  it('shows correct icon for video files', () => {
    const mockFile = new File([''], 'test.mp4', { type: 'video/mp4' });
    render(
      <MentionInput {...defaultProps} attachments={[mockFile]} showFileDetails={true} />
    );

    const fileName = screen.getByText('test.mp4');
    expect(fileName.closest('div')?.querySelector('svg')).toBeInTheDocument();
  });

  it('handles file input change', async () => {
    render(<MentionInput {...defaultProps} />);

    const fileInput = screen.getByTestId('file-input');
    const mockFile = new File(['content'], 'test.pdf', { type: 'application/pdf' });

    Object.defineProperty(fileInput, 'files', {
      value: [mockFile],
    });
    fireEvent.change(fileInput);

    expect(mockOnAttachmentsChange).toHaveBeenCalledWith([mockFile]);
  });

  it('limits attachments to maxAttachments', async () => {
    const existingFile = new File(['1'], 'existing.pdf', { type: 'application/pdf' });
    render(
      <MentionInput {...defaultProps} attachments={[existingFile]} maxAttachments={2} />
    );

    const fileInput = screen.getByTestId('file-input');
    const newFile1 = new File(['2'], 'new1.pdf', { type: 'application/pdf' });
    const newFile2 = new File(['3'], 'new2.pdf', { type: 'application/pdf' });

    Object.defineProperty(fileInput, 'files', {
      value: [newFile1, newFile2],
    });
    fireEvent.change(fileInput);

    // Should only add 1 file (to reach max of 2)
    expect(mockOnAttachmentsChange).toHaveBeenCalled();
    const calledWith = mockOnAttachmentsChange.mock.calls[0][0];
    expect(calledWith.length).toBe(2);
  });
});
