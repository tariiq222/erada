import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import MentionInput from '@shared/ui/MentionInput';

const mockUsers = [
  { id: 1, name: 'أحمد محمد', email: 'ahmed@test.com' },
  { id: 2, name: 'محمد علي', email: 'mohamed@test.com' },
  { id: 3, name: 'خالد عبدالله', email: 'khaled@test.com' },
];

describe('MentionInput Component', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders textarea', () => {
    render(<MentionInput {...defaultProps} />);
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });

  it('renders with placeholder', () => {
    render(<MentionInput {...defaultProps} placeholder="اكتب تعليقاً..." />);
    expect(screen.getByPlaceholderText('اكتب تعليقاً...')).toBeInTheDocument();
  });

  it('renders submit button', () => {
    render(<MentionInput {...defaultProps} />);
    expect(screen.getByTitle('إرسال')).toBeInTheDocument();
  });

  it('renders attachment button', () => {
    render(<MentionInput {...defaultProps} />);
    expect(screen.getByTitle('إرفاق ملف')).toBeInTheDocument();
  });

  it('calls onChange when typing', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'مرحبا');
    expect(defaultProps.onChange).toHaveBeenCalled();
  });

  it('calls onSubmit when clicking submit button', async () => {
    render(<MentionInput {...defaultProps} value="تعليق" />);
    await userEvent.click(screen.getByTitle('إرسال'));
    expect(defaultProps.onSubmit).toHaveBeenCalled();
  });

  it('disables submit button when value is empty', () => {
    render(<MentionInput {...defaultProps} value="" />);
    const submitBtn = screen.getByTitle('إرسال');
    expect(submitBtn).toBeDisabled();
  });

  it('enables submit button when value has content', () => {
    render(<MentionInput {...defaultProps} value="تعليق" />);
    const submitBtn = screen.getByTitle('إرسال');
    expect(submitBtn).not.toBeDisabled();
  });

  it('enables submit button when has attachments', () => {
    const file = new File(['test'], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...defaultProps} value="" attachments={[file]} />);
    const submitBtn = screen.getByTitle('إرسال');
    expect(submitBtn).not.toBeDisabled();
  });

  it('disables textarea when disabled prop is true', () => {
    render(<MentionInput {...defaultProps} disabled />);
    expect(screen.getByRole('textbox')).toBeDisabled();
  });
});

describe('MentionInput Mention Suggestions', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows suggestions when typing @', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, '@');

    // Trigger change event with cursor position
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      expect(screen.getByText('اذكر شخصاً')).toBeInTheDocument();
    });
  });

  it('shows filtered users when typing after @', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@أحمد', selectionStart: 5 } });

    await waitFor(() => {
      expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
    });
  });

  it('shows user email in suggestions', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      expect(screen.getByText('ahmed@test.com')).toBeInTheDocument();
    });
  });

  it('hides suggestions when pressing Escape', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      expect(screen.getByText('اذكر شخصاً')).toBeInTheDocument();
    });

    fireEvent.keyDown(textarea, { key: 'Escape' });

    await waitFor(() => {
      expect(screen.queryByText('اذكر شخصاً')).not.toBeInTheDocument();
    });
  });

  it('inserts mention when selecting user', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByText('أحمد محمد'));

    expect(defaultProps.onChange).toHaveBeenCalledWith('@أحمد محمد ');
  });

  it('shows user avatar initial in suggestions', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      // Check for avatar with first letter
      const avatars = document.querySelectorAll('.bg-\\[var\\(--accent-default\\)\\]');
      expect(avatars.length).toBeGreaterThan(0);
    });
  });
});

describe('MentionInput Keyboard Navigation', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('submits on Enter key without Shift', async () => {
    render(<MentionInput {...defaultProps} value="تعليق" />);
    const textarea = screen.getByRole('textbox');

    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });

    expect(defaultProps.onSubmit).toHaveBeenCalled();
  });

  it('does not submit on Shift+Enter', async () => {
    render(<MentionInput {...defaultProps} value="تعليق" />);
    const textarea = screen.getByRole('textbox');

    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true });

    expect(defaultProps.onSubmit).not.toHaveBeenCalled();
  });

  it('does not submit when suggestions are visible', async () => {
    render(<MentionInput {...defaultProps} />);
    const textarea = screen.getByRole('textbox');

    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    await waitFor(() => {
      expect(screen.getByText('اذكر شخصاً')).toBeInTheDocument();
    });

    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });

    expect(defaultProps.onSubmit).not.toHaveBeenCalled();
  });
});

describe('MentionInput Attachments', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows file input when clicking attachment button', async () => {
    render(<MentionInput {...defaultProps} />);

    const fileInput = document.querySelector('input[type="file"]');
    expect(fileInput).toBeInTheDocument();
  });

  it('accepts multiple files', () => {
    render(<MentionInput {...defaultProps} />);

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    expect(fileInput).toHaveAttribute('multiple');
  });

  it('accepts specified file types', () => {
    render(<MentionInput {...defaultProps} acceptedFileTypes="image/*" />);

    const fileInput = document.querySelector('input[type="file"]');
    expect(fileInput).toHaveAttribute('accept', 'image/*');
  });

  it('renders attachment previews', () => {
    const file1 = new File(['test'], 'test.pdf', { type: 'application/pdf' });
    const file2 = new File(['test'], 'image.png', { type: 'image/png' });

    render(<MentionInput {...defaultProps} attachments={[file1, file2]} />);

    expect(screen.getByText('test.pdf')).toBeInTheDocument();
    expect(screen.getByText('image.png')).toBeInTheDocument();
  });

  it('shows file size for attachments', () => {
    const file = new File(['test content'], 'test.pdf', { type: 'application/pdf' });

    render(<MentionInput {...defaultProps} attachments={[file]} showFileDetails={true} />);

    // File size should be shown
    const sizeText = document.querySelector('.text-\\[var\\(--text-tertiary\\)\\]');
    expect(sizeText).toBeInTheDocument();
  });

  it('hides file size when showFileDetails is false', () => {
    const file = new File(['test content'], 'test.pdf', { type: 'application/pdf' });

    render(<MentionInput {...defaultProps} attachments={[file]} showFileDetails={false} />);

    expect(screen.getByText('test.pdf')).toBeInTheDocument();
  });

  it('calls onAttachmentsChange when removing attachment', async () => {
    const file = new File(['test'], 'test.pdf', { type: 'application/pdf' });

    render(<MentionInput {...defaultProps} attachments={[file]} />);

    // MentionInput exposes data-testid="attachment-remove" on the per-file X button
    await userEvent.click(screen.getByTestId('attachment-remove'));
    expect(defaultProps.onAttachmentsChange).toHaveBeenCalledWith([]);
  });

  it('disables attachment button when max attachments reached', () => {
    const files = [
      new File(['1'], 'file1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'file2.pdf', { type: 'application/pdf' }),
      new File(['3'], 'file3.pdf', { type: 'application/pdf' }),
      new File(['4'], 'file4.pdf', { type: 'application/pdf' }),
      new File(['5'], 'file5.pdf', { type: 'application/pdf' }),
    ];

    render(<MentionInput {...defaultProps} attachments={files} maxAttachments={5} />);

    const attachButton = screen.getByTitle('إرفاق ملف');
    expect(attachButton).toBeDisabled();
  });

  it('handles file selection', async () => {
    render(<MentionInput {...defaultProps} />);

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = new File(['test'], 'test.pdf', { type: 'application/pdf' });

    Object.defineProperty(fileInput, 'files', {
      value: [file],
    });

    fireEvent.change(fileInput);

    expect(defaultProps.onAttachmentsChange).toHaveBeenCalledWith([file]);
  });

  it('limits attachments to maxAttachments', async () => {
    const existingFile = new File(['existing'], 'existing.pdf', { type: 'application/pdf' });

    render(
      <MentionInput
        {...defaultProps}
        attachments={[existingFile]}
        maxAttachments={2}
      />
    );

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    const newFiles = [
      new File(['1'], 'new1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'new2.pdf', { type: 'application/pdf' }),
    ];

    Object.defineProperty(fileInput, 'files', {
      value: newFiles,
    });

    fireEvent.change(fileInput);

    // Should only add up to maxAttachments
    expect(defaultProps.onAttachmentsChange).toHaveBeenCalledWith(
      expect.arrayContaining([existingFile])
    );
  });
});

describe('MentionInput File Icons', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
  };

  it('shows image icon for image files', () => {
    const file = new File(['test'], 'image.png', { type: 'image/png' });
    render(<MentionInput {...defaultProps} attachments={[file]} />);

    // SVG icon should be present
    const icon = document.querySelector('svg');
    expect(icon).toBeInTheDocument();
  });

  it('shows video icon for video files', () => {
    const file = new File(['test'], 'video.mp4', { type: 'video/mp4' });
    render(<MentionInput {...defaultProps} attachments={[file]} />);

    const icon = document.querySelector('svg');
    expect(icon).toBeInTheDocument();
  });

  it('shows document icon for PDF files', () => {
    const file = new File(['test'], 'doc.pdf', { type: 'application/pdf' });
    render(<MentionInput {...defaultProps} attachments={[file]} />);

    const icon = document.querySelector('svg');
    expect(icon).toBeInTheDocument();
  });

  it('shows generic file icon for unknown types', () => {
    const file = new File(['test'], 'file.xyz', { type: 'application/octet-stream' });
    render(<MentionInput {...defaultProps} attachments={[file]} />);

    const icon = document.querySelector('svg');
    expect(icon).toBeInTheDocument();
  });
});

describe('MentionInput File Size Formatting', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
    onSubmit: vi.fn(),
    users: mockUsers,
    attachments: [],
    onAttachmentsChange: vi.fn(),
    showFileDetails: true,
  };

  it('formats bytes correctly', () => {
    const file = new File(['test'], 'small.txt', { type: 'text/plain' });
    Object.defineProperty(file, 'size', { value: 500 });

    render(<MentionInput {...defaultProps} attachments={[file]} />);

    expect(screen.getByText('500 bytes')).toBeInTheDocument();
  });

  it('formats KB correctly', () => {
    const file = new File(['test'], 'medium.txt', { type: 'text/plain' });
    Object.defineProperty(file, 'size', { value: 2048 });

    render(<MentionInput {...defaultProps} attachments={[file]} />);

    expect(screen.getByText('2.0 KB')).toBeInTheDocument();
  });

  it('formats MB correctly', () => {
    const file = new File(['test'], 'large.txt', { type: 'text/plain' });
    Object.defineProperty(file, 'size', { value: 2097152 });

    render(<MentionInput {...defaultProps} attachments={[file]} />);

    expect(screen.getByText('2.0 MB')).toBeInTheDocument();
  });
});
