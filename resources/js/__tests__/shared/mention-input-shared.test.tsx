import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconSend: () => <span data-testid="send-icon">Send</span>,
  IconAt: () => <span data-testid="at-icon">AtSign</span>,
  IconPaperclip: () => <span data-testid="paperclip-icon">Paperclip</span>,
  IconFileText: () => <span data-testid="file-text-icon">FileText</span>,
  IconPhoto: () => <span data-testid="image-icon">Image</span>,
  IconMovie: () => <span data-testid="film-icon">Film</span>,
  IconFile: () => <span data-testid="file-icon">File</span>,
  IconX: () => <span data-testid="x-icon">X</span>,

  };
});

import MentionInput from '@shared/ui/MentionInput';

const mockUsers = [
  { id: 1, name: 'أحمد محمد', email: 'ahmed@example.com' },
  { id: 2, name: 'سارة أحمد', email: 'sara@example.com' },
  { id: 3, name: 'محمد علي', email: 'mohamed@example.com' },
];

const createDefaultProps = (overrides: any = {}) => ({
  value: '',
  onChange: vi.fn(),
  onSubmit: vi.fn(),
  users: mockUsers,
  disabled: false,
  placeholder: 'اكتب تعليقاً...',
  attachments: [],
  onAttachmentsChange: vi.fn(),
  ...overrides,
});

describe('MentionInput Basic', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders textarea', () => {
    render(<MentionInput {...createDefaultProps()} />);
    expect(screen.getByPlaceholderText('اكتب تعليقاً...')).toBeInTheDocument();
  });

  it('renders send button', () => {
    render(<MentionInput {...createDefaultProps()} />);
    expect(screen.getByTestId('send-icon')).toBeInTheDocument();
  });

  it('renders attachment button', () => {
    render(<MentionInput {...createDefaultProps()} />);
    expect(screen.getByTestId('paperclip-icon')).toBeInTheDocument();
  });

  it('displays value in textarea', () => {
    render(<MentionInput {...createDefaultProps({ value: 'مرحباً' })} />);
    expect(screen.getByDisplayValue('مرحباً')).toBeInTheDocument();
  });

  it('calls onChange when typing', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: 'نص جديد', selectionStart: 7 } });
    expect(props.onChange).toHaveBeenCalledWith('نص جديد');
  });
});

describe('MentionInput Submit', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onSubmit when send button clicked', () => {
    const props = createDefaultProps({ value: 'تعليق' });
    render(<MentionInput {...props} />);
    const sendButton = screen.getByTitle('إرسال');
    fireEvent.click(sendButton);
    expect(props.onSubmit).toHaveBeenCalled();
  });

  it('disables send when empty and no attachments', () => {
    render(<MentionInput {...createDefaultProps()} />);
    const sendButton = screen.getByTitle('إرسال');
    expect(sendButton).toBeDisabled();
  });

  it('enables send when has text', () => {
    render(<MentionInput {...createDefaultProps({ value: 'نص' })} />);
    const sendButton = screen.getByTitle('إرسال');
    expect(sendButton).not.toBeDisabled();
  });

  it('calls onSubmit on Enter key', () => {
    const props = createDefaultProps({ value: 'تعليق' });
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });
    expect(props.onSubmit).toHaveBeenCalled();
  });

  it('does not submit on Shift+Enter', () => {
    const props = createDefaultProps({ value: 'تعليق' });
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true });
    expect(props.onSubmit).not.toHaveBeenCalled();
  });
});

describe('MentionInput Disabled State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('disables textarea when disabled', () => {
    render(<MentionInput {...createDefaultProps({ disabled: true })} />);
    expect(screen.getByPlaceholderText('اكتب تعليقاً...')).toBeDisabled();
  });

  it('disables send button when disabled', () => {
    render(<MentionInput {...createDefaultProps({ disabled: true, value: 'نص' })} />);
    const sendButton = screen.getByTitle('إرسال');
    expect(sendButton).toBeDisabled();
  });

  it('disables attachment button when disabled', () => {
    render(<MentionInput {...createDefaultProps({ disabled: true })} />);
    const attachButton = screen.getByTitle('إرفاق ملف');
    expect(attachButton).toBeDisabled();
  });
});

describe('MentionInput Attachments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows attachment preview when has files', () => {
    const file = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByText('test.pdf')).toBeInTheDocument();
  });

  it('shows file size for attachments', () => {
    const file = new File(['a'.repeat(2048)], 'large.pdf', { type: 'application/pdf' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByText('2.0 KB')).toBeInTheDocument();
  });

  it('shows remove button for attachments', () => {
    const file = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByTestId('x-icon')).toBeInTheDocument();
  });

  it('calls onAttachmentsChange when removing file', () => {
    const file = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    const props = createDefaultProps({ attachments: [file] });
    render(<MentionInput {...props} />);
    const removeButton = screen.getByTestId('x-icon').closest('button');
    fireEvent.click(removeButton!);
    expect(props.onAttachmentsChange).toHaveBeenCalledWith([]);
  });

  it('enables send when has attachments only', () => {
    const file = new File(['content'], 'test.pdf', { type: 'application/pdf' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    const sendButton = screen.getByTitle('إرسال');
    expect(sendButton).not.toBeDisabled();
  });

  it('disables attachment button when max reached', () => {
    const files = [
      new File(['1'], 'f1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'f2.pdf', { type: 'application/pdf' }),
      new File(['3'], 'f3.pdf', { type: 'application/pdf' }),
      new File(['4'], 'f4.pdf', { type: 'application/pdf' }),
      new File(['5'], 'f5.pdf', { type: 'application/pdf' }),
    ];
    render(<MentionInput {...createDefaultProps({ attachments: files, maxAttachments: 5 })} />);
    const attachButton = screen.getByTitle('إرفاق ملف');
    expect(attachButton).toBeDisabled();
  });
});

describe('MentionInput File Size Formatting', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows bytes for small files', () => {
    const file = new File(['123'], 'tiny.txt', { type: 'text/plain' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByText('3 bytes')).toBeInTheDocument();
  });

  it('shows KB for kilobyte files', () => {
    const file = new File(['a'.repeat(1500)], 'medium.txt', { type: 'text/plain' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByText(/KB/)).toBeInTheDocument();
  });

  it('shows MB for megabyte files', () => {
    const file = new File(['a'.repeat(1500000)], 'big.txt', { type: 'text/plain' });
    render(<MentionInput {...createDefaultProps({ attachments: [file] })} />);
    expect(screen.getByText(/MB/)).toBeInTheDocument();
  });
});

describe('MentionInput Mentions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows suggestions when typing @', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });
    // Suggestions should appear
    expect(screen.getByText('اذكر شخصاً')).toBeInTheDocument();
  });

  it('shows filtered users in suggestions', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: '@أحمد', selectionStart: 5 } });
    expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
  });

  it('shows user emails in suggestions', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });
    expect(screen.getByText('ahmed@example.com')).toBeInTheDocument();
  });

  it('hides suggestions on Escape', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });
    fireEvent.keyDown(textarea, { key: 'Escape' });
    expect(screen.queryByText('اذكر شخصاً')).not.toBeInTheDocument();
  });

  it('inserts mention when user selected', () => {
    const props = createDefaultProps();
    render(<MentionInput {...props} />);
    const textarea = screen.getByPlaceholderText('اكتب تعليقاً...');
    fireEvent.change(textarea, { target: { value: '@', selectionStart: 1 } });

    const userButton = screen.getByText('أحمد محمد').closest('button');
    fireEvent.click(userButton!);

    expect(props.onChange).toHaveBeenCalledWith('@أحمد محمد ');
  });
});

describe('MentionInput Multiple Attachments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows all attachment names', () => {
    const files = [
      new File(['1'], 'file1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'file2.pdf', { type: 'application/pdf' }),
    ];
    render(<MentionInput {...createDefaultProps({ attachments: files })} />);
    expect(screen.getByText('file1.pdf')).toBeInTheDocument();
    expect(screen.getByText('file2.pdf')).toBeInTheDocument();
  });

  it('shows multiple remove buttons', () => {
    const files = [
      new File(['1'], 'file1.pdf', { type: 'application/pdf' }),
      new File(['2'], 'file2.pdf', { type: 'application/pdf' }),
    ];
    render(<MentionInput {...createDefaultProps({ attachments: files })} />);
    expect(screen.getAllByTestId('x-icon').length).toBe(2);
  });
});
