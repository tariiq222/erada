import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock scrollIntoView
beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

import { DatePicker } from '@shared/ui/DatePicker';

describe('DatePicker Component', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders with default placeholder text', () => {
    render(<DatePicker {...defaultProps} />);
    expect(screen.getByText('اختر التاريخ')).toBeInTheDocument();
  });

  it('renders with custom placeholder', () => {
    render(<DatePicker {...defaultProps} placeholder="تاريخ البداية" />);
    expect(screen.getByText('تاريخ البداية')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<DatePicker {...defaultProps} label="تاريخ الميلاد" />);
    expect(screen.getByText('تاريخ الميلاد')).toBeInTheDocument();
  });

  it('renders required indicator when required', () => {
    render(<DatePicker {...defaultProps} label="التاريخ" required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders error message', () => {
    render(<DatePicker {...defaultProps} error="هذا الحقل مطلوب" />);
    expect(screen.getByText('هذا الحقل مطلوب')).toBeInTheDocument();
  });

  it('renders hint text', () => {
    render(<DatePicker {...defaultProps} hint="اختر تاريخ بداية المشروع" />);
    expect(screen.getByText('اختر تاريخ بداية المشروع')).toBeInTheDocument();
  });

  it('displays formatted date when value is set', () => {
    render(<DatePicker {...defaultProps} value="2025-01-15" />);
    // Date is displayed in Arabic format: "15 يناير 2025"
    expect(screen.getByText(/15.*يناير.*2025/)).toBeInTheDocument();
  });

  it('is disabled when disabled prop is true', () => {
    render(<DatePicker {...defaultProps} disabled />);
    // The main button should be disabled
    const trigger = screen.getByText('اختر التاريخ').closest('button');
    expect(trigger).toBeDisabled();
  });

  it('opens calendar when trigger clicked', async () => {
    render(<DatePicker {...defaultProps} />);

    const trigger = screen.getByText('اختر التاريخ');
    await userEvent.click(trigger);

    // Arabic month names should be visible
    expect(screen.getByText(/يناير|فبراير|مارس|أبريل|مايو|يونيو|يوليو|أغسطس|سبتمبر|أكتوبر|نوفمبر|ديسمبر/)).toBeInTheDocument();
  });

  it('renders Arabic weekday headers', async () => {
    render(<DatePicker {...defaultProps} />);

    await userEvent.click(screen.getByText('اختر التاريخ'));

    // Should show Arabic day abbreviations
    expect(screen.getByText('أحد')).toBeInTheDocument();
    expect(screen.getByText('إثن')).toBeInTheDocument();
    expect(screen.getByText('ثلا')).toBeInTheDocument();
    expect(screen.getByText('أرب')).toBeInTheDocument();
    expect(screen.getByText('خمي')).toBeInTheDocument();
    expect(screen.getByText('جمع')).toBeInTheDocument();
    expect(screen.getByText('سبت')).toBeInTheDocument();
  });

  it('closes calendar when clicking outside', async () => {
    render(
      <div>
        <DatePicker {...defaultProps} />
        <button>Outside</button>
      </div>
    );

    await userEvent.click(screen.getByText('اختر التاريخ'));

    // Calendar should be open
    expect(screen.getByText('أحد')).toBeInTheDocument();

    // Click outside
    fireEvent.mouseDown(screen.getByText('Outside'));

    // Calendar should be closed (weekday headers should be gone)
    expect(screen.queryByText('أحد')).not.toBeInTheDocument();
  });

  it('renders calendar icon', () => {
    render(<DatePicker {...defaultProps} />);

    const calendarIcon = document.querySelector('svg.tabler-icon-calendar');
    expect(calendarIcon).toBeInTheDocument();
  });
});

describe('DatePicker Date Selection', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onChange when date is selected', async () => {
    const onChange = vi.fn();
    render(<DatePicker {...defaultProps} onChange={onChange} />);

    await userEvent.click(screen.getByText('اختر التاريخ'));

    // Find and click a day button (15)
    const dayButtons = screen.getAllByRole('button').filter(
      btn => btn.textContent === '15'
    );

    if (dayButtons.length > 0) {
      await userEvent.click(dayButtons[0]);
    }

    expect(onChange).toHaveBeenCalled();
  });
});

describe('DatePicker Year/Month Picker', () => {
  const defaultProps = {
    value: '2025-06-15',
    onChange: vi.fn(),
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows year picker when year clicked', async () => {
    render(<DatePicker {...defaultProps} />);

    // Open the date picker first - formatted date in Arabic "15 يونيو 2025"
    const dateText = screen.getByText(/15.*يونيو.*2025/);
    await userEvent.click(dateText);

    // Click on the year
    const yearButton = screen.getByText('2025');
    await userEvent.click(yearButton);

    // Year grid should be visible
    const yearButtons = screen.getAllByRole('button').filter(
      btn => btn.textContent?.match(/^20\d{2}$/)
    );
    expect(yearButtons.length).toBeGreaterThan(0);
  });

  it('shows month picker when month clicked', async () => {
    render(<DatePicker {...defaultProps} />);

    // Open the date picker first - formatted date in Arabic
    const dateText = screen.getByText(/15.*يونيو.*2025/);
    await userEvent.click(dateText);

    // Click on the month
    const monthButton = screen.getByText('يونيو');
    await userEvent.click(monthButton);

    // Month grid should be visible
    expect(screen.getByText('يناير')).toBeInTheDocument();
    expect(screen.getByText('فبراير')).toBeInTheDocument();
    expect(screen.getByText('ديسمبر')).toBeInTheDocument();
  });
});

describe('DatePicker Keyboard Navigation', () => {
  it('closes on Escape key', async () => {
    render(<DatePicker value="" onChange={vi.fn()} />);

    await userEvent.click(screen.getByText('اختر التاريخ'));

    // Calendar should be open
    expect(screen.getByText('أحد')).toBeInTheDocument();

    // Press Escape
    fireEvent.keyDown(document, { key: 'Escape' });

    // Calendar should be closed
    expect(screen.queryByText('أحد')).not.toBeInTheDocument();
  });
});
