import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconChevronDown: () => <span data-testid="chevron-icon">ChevronDown</span>,
  IconCheck: () => <span data-testid="check-icon">Check</span>,
  IconSearch: () => <span data-testid="search-icon">Search</span>,

  };
});

import { Select } from '@shared/ui/Select';

const mockOptions = [
  { value: 'option1', label: 'خيار 1' },
  { value: 'option2', label: 'خيار 2' },
  { value: 'option3', label: 'خيار 3' },
];

describe('Select Basic', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders select button', () => {
    render(<Select options={mockOptions} />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('shows placeholder by default', () => {
    render(<Select options={mockOptions} />);
    expect(screen.getByText('-- اختر --')).toBeInTheDocument();
  });

  it('shows custom placeholder', () => {
    render(<Select options={mockOptions} placeholder="اختر قيمة" />);
    expect(screen.getByText('اختر قيمة')).toBeInTheDocument();
  });

  it('renders chevron icon', () => {
    render(<Select options={mockOptions} />);
    expect(screen.getByTestId('chevron-icon')).toBeInTheDocument();
  });

  it('has aria-haspopup', () => {
    render(<Select options={mockOptions} />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-haspopup', 'listbox');
  });

  it('has aria-expanded false initially', () => {
    render(<Select options={mockOptions} />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Select Label', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders label when provided', () => {
    render(<Select options={mockOptions} label="اختر القسم" />);
    expect(screen.getByText('اختر القسم')).toBeInTheDocument();
  });

  it('shows required indicator', () => {
    render(<Select options={mockOptions} label="اختر القسم" required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });
});

describe('Select Open/Close', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('opens dropdown on click', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  });

  it('shows options when open', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('shows all option labels', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('خيار 1')).toBeInTheDocument();
    expect(screen.getByText('خيار 2')).toBeInTheDocument();
    expect(screen.getByText('خيار 3')).toBeInTheDocument();
  });

  it('closes on click again', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Select Selection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onChange when option selected', () => {
    const onChange = vi.fn();
    render(<Select options={mockOptions} onChange={onChange} />);
    fireEvent.click(screen.getByRole('button'));
    // Find option in the listbox
    const listbox = screen.getByRole('listbox');
    const option = listbox.querySelector('[role="option"]:nth-child(2)');
    fireEvent.click(option!);
    expect(onChange).toHaveBeenCalledWith({ target: { value: 'option2' } });
  });

  it('shows selected value in button', () => {
    render(<Select options={mockOptions} value="option2" />);
    // The button shows the selected label
    expect(screen.getByRole('button')).toHaveTextContent('خيار 2');
  });

  it('shows check icon for selected option', () => {
    render(<Select options={mockOptions} value="option1" />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByTestId('check-icon')).toBeInTheDocument();
  });

  it('closes after selection', () => {
    const onChange = vi.fn();
    render(<Select options={mockOptions} onChange={onChange} />);
    fireEvent.click(screen.getByRole('button'));
    const listbox = screen.getByRole('listbox');
    const option = listbox.querySelector('[role="option"]');
    fireEvent.click(option!);
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Select Disabled State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('disables button when disabled', () => {
    render(<Select options={mockOptions} disabled />);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('does not open when disabled', () => {
    render(<Select options={mockOptions} disabled />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Select Error State', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows error message', () => {
    render(<Select options={mockOptions} error="هذا الحقل مطلوب" />);
    expect(screen.getByText('هذا الحقل مطلوب')).toBeInTheDocument();
  });

  it('sets aria-invalid to true', () => {
    render(<Select options={mockOptions} error="خطأ" />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('Select Hint', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows hint when provided', () => {
    render(<Select options={mockOptions} hint="اختر من القائمة" />);
    expect(screen.getByText('اختر من القائمة')).toBeInTheDocument();
  });

  it('hides hint when error is shown', () => {
    render(<Select options={mockOptions} hint="تلميح" error="خطأ" />);
    expect(screen.queryByText('تلميح')).not.toBeInTheDocument();
  });
});

describe('Select Keyboard Navigation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Mock scrollIntoView
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('opens on Enter', () => {
    render(<Select options={mockOptions} />);
    fireEvent.keyDown(screen.getByRole('button'), { key: 'Enter' });
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  });

  it('opens on Space', () => {
    render(<Select options={mockOptions} />);
    fireEvent.keyDown(screen.getByRole('button'), { key: ' ' });
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  });

  it('closes on Escape', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.keyDown(screen.getByRole('button'), { key: 'Escape' });
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });

  it('opens on ArrowDown', () => {
    render(<Select options={mockOptions} />);
    fireEvent.keyDown(screen.getByRole('button'), { key: 'ArrowDown' });
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  });

  it('closes on Tab', () => {
    render(<Select options={mockOptions} />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.keyDown(screen.getByRole('button'), { key: 'Tab' });
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Select Searchable', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('shows search when searchable', () => {
    render(<Select options={mockOptions} searchable />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByPlaceholderText('ابحث...')).toBeInTheDocument();
  });

  it('shows search icon', () => {
    render(<Select options={mockOptions} searchable />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByTestId('search-icon')).toBeInTheDocument();
  });

  it('filters options by search', () => {
    render(<Select options={mockOptions} searchable />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.change(screen.getByPlaceholderText('ابحث...'), { target: { value: 'خيار 1' } });
    // Should show only matching option in the listbox
    const options = screen.getAllByRole('option');
    expect(options.length).toBe(1);
  });

  it('shows no results message', () => {
    render(<Select options={mockOptions} searchable />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.change(screen.getByPlaceholderText('ابحث...'), { target: { value: 'غير موجود' } });
    expect(screen.getByText('لا توجد نتائج للبحث')).toBeInTheDocument();
  });

  it('auto shows search for many options', () => {
    const manyOptions = Array.from({ length: 15 }, (_, i) => ({
      value: `opt${i}`,
      label: `خيار ${i}`,
    }));
    render(<Select options={manyOptions} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByPlaceholderText('ابحث...')).toBeInTheDocument();
  });
});

describe('Select Disabled Options', () => {
  const optionsWithDisabled = [
    { value: 'option1', label: 'خيار 1' },
    { value: 'option2', label: 'خيار 2', disabled: true },
    { value: 'option3', label: 'خيار 3' },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('marks disabled option with aria-disabled', () => {
    render(<Select options={optionsWithDisabled} />);
    fireEvent.click(screen.getByRole('button'));
    const options = screen.getAllByRole('option');
    expect(options[1]).toHaveAttribute('aria-disabled', 'true');
  });

  it('does not select disabled option on click', () => {
    const onChange = vi.fn();
    render(<Select options={optionsWithDisabled} onChange={onChange} />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.click(screen.getByText('خيار 2'));
    expect(onChange).not.toHaveBeenCalled();
  });
});

describe('Select Empty Options', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows no options message', () => {
    render(<Select options={[]} />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('لا توجد خيارات')).toBeInTheDocument();
  });
});

describe('Select Custom Props', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('applies custom className', () => {
    render(<Select options={mockOptions} className="custom-class" />);
    expect(screen.getByRole('button')).toHaveClass('custom-class');
  });

  it('uses custom id', () => {
    render(<Select options={mockOptions} id="my-select" />);
    expect(screen.getByRole('button')).toHaveAttribute('id', 'my-select');
  });

  it('stores value in hidden input', () => {
    render(<Select options={mockOptions} name="my-field" value="option2" />);
    const hiddenInput = document.querySelector('input[type="hidden"]');
    expect(hiddenInput).toHaveValue('option2');
    expect(hiddenInput).toHaveAttribute('name', 'my-field');
  });

  it('uses defaultValue for uncontrolled mode', () => {
    render(<Select options={mockOptions} defaultValue="option3" />);
    expect(screen.getByRole('button')).toHaveTextContent('خيار 3');
  });
});
