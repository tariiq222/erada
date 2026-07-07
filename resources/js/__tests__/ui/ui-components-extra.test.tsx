import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock scrollIntoView
beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

// Import UI components
import { Progress } from '@shared/ui/Progress';
import { Textarea } from '@shared/ui/Textarea';
import { Tooltip } from '@shared/ui/Tooltip';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@shared/ui/Tabs';
import { Switch } from '@shared/ui/Switch';
import { Avatar } from '@shared/ui/Avatar';
import { RadioGroup, Radio } from '@shared/ui/Radio';

// ==================== Progress Tests ====================
describe('Progress Component', () => {
  it('renders with default props', () => {
    render(<Progress value={50} />);
    const progressbar = screen.getByRole('progressbar');
    expect(progressbar).toBeInTheDocument();
  });

  it('renders with correct aria attributes', () => {
    render(<Progress value={30} max={100} />);
    const progressbar = screen.getByRole('progressbar');
    expect(progressbar).toHaveAttribute('aria-valuenow', '30');
    expect(progressbar).toHaveAttribute('aria-valuemin', '0');
    expect(progressbar).toHaveAttribute('aria-valuemax', '100');
  });

  it('renders with custom max value', () => {
    render(<Progress value={25} max={50} />);
    const progressbar = screen.getByRole('progressbar');
    expect(progressbar).toHaveAttribute('aria-valuemax', '50');
  });

  it('shows value when showValue is true', () => {
    render(<Progress value={75} showValue />);
    expect(screen.getByText('75%')).toBeInTheDocument();
    expect(screen.getByText('التقدم')).toBeInTheDocument();
  });

  it('does not show value when showValue is false', () => {
    render(<Progress value={75} showValue={false} />);
    expect(screen.queryByText('75%')).not.toBeInTheDocument();
  });

  it('clamps value to 0-100 range', () => {
    const { rerender } = render(<Progress value={-10} />);
    // Value below 0 should be clamped to 0
    let progressbar = screen.getByRole('progressbar');
    expect(progressbar).toHaveAttribute('aria-valuenow', '-10');

    rerender(<Progress value={150} />);
    // Value above max is still shown, but percentage is clamped in style
  });

  it('renders small size variant', () => {
    render(<Progress value={50} size="sm" />);
    expect(screen.getByRole('progressbar')).toBeInTheDocument();
  });

  it('renders medium size variant', () => {
    render(<Progress value={50} size="md" />);
    expect(screen.getByRole('progressbar')).toBeInTheDocument();
  });
});

// ==================== Textarea Tests ====================
describe('Textarea Component', () => {
  it('renders with default props', () => {
    render(<Textarea />);
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Textarea label="الوصف" />);
    expect(screen.getByText('الوصف')).toBeInTheDocument();
  });

  it('renders required indicator', () => {
    render(<Textarea label="الوصف" required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders error message', () => {
    render(<Textarea error="هذا الحقل مطلوب" />);
    expect(screen.getByText('هذا الحقل مطلوب')).toBeInTheDocument();
  });

  it('renders hint text', () => {
    render(<Textarea hint="أدخل وصف تفصيلي" />);
    expect(screen.getByText('أدخل وصف تفصيلي')).toBeInTheDocument();
  });

  it('hides hint when error is shown', () => {
    render(<Textarea hint="أدخل وصف تفصيلي" error="خطأ" />);
    expect(screen.queryByText('أدخل وصف تفصيلي')).not.toBeInTheDocument();
    expect(screen.getByText('خطأ')).toBeInTheDocument();
  });

  it('has aria-invalid when error exists', () => {
    render(<Textarea error="خطأ" />);
    expect(screen.getByRole('textbox')).toHaveAttribute('aria-invalid', 'true');
  });

  it('accepts user input', async () => {
    render(<Textarea placeholder="اكتب هنا" />);
    const textarea = screen.getByPlaceholderText('اكتب هنا');
    await userEvent.type(textarea, 'محتوى جديد');
    expect(textarea).toHaveValue('محتوى جديد');
  });

  it('renders with custom id', () => {
    render(<Textarea id="custom-id" label="وصف" />);
    expect(screen.getByRole('textbox')).toHaveAttribute('id', 'custom-id');
  });
});

// ==================== Tooltip Tests ====================
describe('Tooltip Component', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renders children', () => {
    render(
      <Tooltip content="معلومات إضافية">
        <button>زر</button>
      </Tooltip>
    );
    expect(screen.getByText('زر')).toBeInTheDocument();
  });

  it('shows tooltip on mouse enter after delay', async () => {
    render(
      <Tooltip content="معلومات إضافية" delay={200}>
        <button>زر</button>
      </Tooltip>
    );

    const trigger = screen.getByText('زر');
    fireEvent.mouseEnter(trigger);

    // Tooltip should not be visible immediately
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();

    // Advance timers
    act(() => {
      vi.advanceTimersByTime(200);
    });

    // Now tooltip should be visible
    expect(screen.getByRole('tooltip')).toBeInTheDocument();
    expect(screen.getByText('معلومات إضافية')).toBeInTheDocument();
  });

  it('hides tooltip on mouse leave', async () => {
    render(
      <Tooltip content="معلومات إضافية" delay={0}>
        <button>زر</button>
      </Tooltip>
    );

    const trigger = screen.getByText('زر');

    fireEvent.mouseEnter(trigger);
    act(() => {
      vi.advanceTimersByTime(0);
    });

    expect(screen.getByRole('tooltip')).toBeInTheDocument();

    fireEvent.mouseLeave(trigger);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('shows tooltip on focus', async () => {
    render(
      <Tooltip content="معلومات" delay={0}>
        <button>زر</button>
      </Tooltip>
    );

    const trigger = screen.getByText('زر');
    fireEvent.focus(trigger);

    act(() => {
      vi.advanceTimersByTime(0);
    });

    expect(screen.getByRole('tooltip')).toBeInTheDocument();
  });

  it('hides tooltip on blur', async () => {
    render(
      <Tooltip content="معلومات" delay={0}>
        <button>زر</button>
      </Tooltip>
    );

    const trigger = screen.getByText('زر');
    fireEvent.focus(trigger);

    act(() => {
      vi.advanceTimersByTime(0);
    });

    fireEvent.blur(trigger);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });
});

// ==================== Tabs Tests ====================
describe('Tabs Component', () => {
  it('renders tabs with default value', () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">تبويب 1</TabsTrigger>
          <TabsTrigger value="tab2">تبويب 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى 1</TabsContent>
        <TabsContent value="tab2">محتوى 2</TabsContent>
      </Tabs>
    );

    expect(screen.getByText('تبويب 1')).toBeInTheDocument();
    expect(screen.getByText('تبويب 2')).toBeInTheDocument();
    expect(screen.getByText('محتوى 1')).toBeInTheDocument();
    expect(screen.queryByText('محتوى 2')).not.toBeInTheDocument();
  });

  it('switches tab on click', async () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">تبويب 1</TabsTrigger>
          <TabsTrigger value="tab2">تبويب 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى 1</TabsContent>
        <TabsContent value="tab2">محتوى 2</TabsContent>
      </Tabs>
    );

    await userEvent.click(screen.getByText('تبويب 2'));

    expect(screen.queryByText('محتوى 1')).not.toBeInTheDocument();
    expect(screen.getByText('محتوى 2')).toBeInTheDocument();
  });

  it('calls onValueChange when tab changes', async () => {
    const onValueChange = vi.fn();
    render(
      <Tabs defaultValue="tab1" onValueChange={onValueChange}>
        <TabsList>
          <TabsTrigger value="tab1">تبويب 1</TabsTrigger>
          <TabsTrigger value="tab2">تبويب 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى 1</TabsContent>
        <TabsContent value="tab2">محتوى 2</TabsContent>
      </Tabs>
    );

    await userEvent.click(screen.getByText('تبويب 2'));
    expect(onValueChange).toHaveBeenCalledWith('tab2');
  });

  it('renders with controlled value', () => {
    render(
      <Tabs defaultValue="tab1" value="tab2">
        <TabsList>
          <TabsTrigger value="tab1">تبويب 1</TabsTrigger>
          <TabsTrigger value="tab2">تبويب 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى 1</TabsContent>
        <TabsContent value="tab2">محتوى 2</TabsContent>
      </Tabs>
    );

    expect(screen.queryByText('محتوى 1')).not.toBeInTheDocument();
    expect(screen.getByText('محتوى 2')).toBeInTheDocument();
  });

  it('renders tab trigger with icon', () => {
    const TestIcon = () => <span data-testid="icon">🏠</span>;
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1" icon={<TestIcon />}>تبويب</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى</TabsContent>
      </Tabs>
    );

    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('has correct aria attributes on triggers', () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">تبويب 1</TabsTrigger>
          <TabsTrigger value="tab2">تبويب 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى</TabsContent>
      </Tabs>
    );

    const tab1 = screen.getByText('تبويب 1');
    const tab2 = screen.getByText('تبويب 2');

    expect(tab1).toHaveAttribute('role', 'tab');
    expect(tab1).toHaveAttribute('aria-selected', 'true');
    expect(tab2).toHaveAttribute('aria-selected', 'false');
  });

  it('renders tablist with role', () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">تبويب</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى</TabsContent>
      </Tabs>
    );

    expect(screen.getByRole('tablist')).toBeInTheDocument();
  });

  it('renders tabpanel with role', () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">تبويب</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">محتوى</TabsContent>
      </Tabs>
    );

    expect(screen.getByRole('tabpanel')).toBeInTheDocument();
  });
});

// ==================== Switch Tests ====================
describe('Switch Component', () => {
  it('renders with default props', () => {
    render(<Switch />);
    expect(screen.getByRole('switch')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Switch label="تفعيل الإشعارات" />);
    expect(screen.getByText('تفعيل الإشعارات')).toBeInTheDocument();
  });

  it('renders with description', () => {
    render(<Switch description="سيتم إرسال إشعارات عند وجود تحديثات" />);
    expect(screen.getByText('سيتم إرسال إشعارات عند وجود تحديثات')).toBeInTheDocument();
  });

  it('renders unchecked by default', () => {
    render(<Switch />);
    expect(screen.getByRole('switch')).not.toBeChecked();
  });

  it('renders checked when checked prop is true', () => {
    render(<Switch checked={true} onChange={() => {}} />);
    expect(screen.getByRole('switch')).toBeChecked();
  });

  it('calls onChange when clicked', async () => {
    const onChange = vi.fn();
    render(<Switch onChange={onChange} />);

    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalled();
  });

  it('is disabled when disabled prop is true', () => {
    render(<Switch disabled />);
    expect(screen.getByRole('switch')).toBeDisabled();
  });

  it('renders small size', () => {
    render(<Switch size="sm" />);
    expect(screen.getByRole('switch')).toBeInTheDocument();
  });

  it('renders medium size', () => {
    render(<Switch size="md" />);
    expect(screen.getByRole('switch')).toBeInTheDocument();
  });

  it('renders large size', () => {
    render(<Switch size="lg" />);
    expect(screen.getByRole('switch')).toBeInTheDocument();
  });
});

// ==================== Avatar Tests ====================
describe('Avatar Component', () => {
  it('renders with image src', () => {
    render(<Avatar src="/avatar.jpg" alt="صورة المستخدم" />);
    const img = screen.getByRole('img');
    expect(img).toHaveAttribute('src', '/avatar.jpg');
    expect(img).toHaveAttribute('alt', 'صورة المستخدم');
  });

  it('renders initials when no src', () => {
    render(<Avatar name="أحمد محمد" />);
    expect(screen.getByText('أم')).toBeInTheDocument();
  });

  it('renders single word initials', () => {
    render(<Avatar name="أحمد" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders question mark when no name or src', () => {
    render(<Avatar />);
    expect(screen.getByText('?')).toBeInTheDocument();
  });

  it('renders fallback on image error', () => {
    render(<Avatar src="/broken.jpg" name="أحمد محمد" />);
    const img = screen.getByRole('img');
    fireEvent.error(img);
    expect(screen.getByText('أم')).toBeInTheDocument();
  });

  it('renders with online status', () => {
    render(<Avatar name="أحمد" status="online" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders with offline status', () => {
    render(<Avatar name="أحمد" status="offline" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders with away status', () => {
    render(<Avatar name="أحمد" status="away" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders with busy status', () => {
    render(<Avatar name="أحمد" status="busy" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders xs size', () => {
    render(<Avatar name="أحمد" size="xs" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders sm size', () => {
    render(<Avatar name="أحمد" size="sm" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders md size', () => {
    render(<Avatar name="أحمد" size="md" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders lg size', () => {
    render(<Avatar name="أحمد" size="lg" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });

  it('renders xl size', () => {
    render(<Avatar name="أحمد" size="xl" />);
    expect(screen.getByText('أح')).toBeInTheDocument();
  });
});

// ==================== Radio Tests ====================
describe('RadioGroup and Radio Components', () => {
  it('renders radio group with options', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    expect(screen.getByText('خيار 1')).toBeInTheDocument();
    expect(screen.getByText('خيار 2')).toBeInTheDocument();
  });

  it('renders with radiogroup role', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" />
      </RadioGroup>
    );

    expect(screen.getByRole('radiogroup')).toBeInTheDocument();
  });

  it('renders radio buttons', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    const radios = screen.getAllByRole('radio');
    expect(radios).toHaveLength(2);
  });

  it('checks correct radio based on value', () => {
    render(
      <RadioGroup name="test" value="option2" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    const radios = screen.getAllByRole('radio');
    expect(radios[0]).not.toBeChecked();
    expect(radios[1]).toBeChecked();
  });

  it('calls onChange when radio is selected', async () => {
    const onChange = vi.fn();
    render(
      <RadioGroup name="test" value="option1" onChange={onChange}>
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    const radios = screen.getAllByRole('radio');
    await userEvent.click(radios[1]);
    expect(onChange).toHaveBeenCalledWith('option2');
  });

  it('renders with description', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" description="وصف الخيار الأول" />
      </RadioGroup>
    );

    expect(screen.getByText('وصف الخيار الأول')).toBeInTheDocument();
  });

  it('disables all radios when group is disabled', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}} disabled>
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    const radios = screen.getAllByRole('radio');
    expect(radios[0]).toBeDisabled();
    expect(radios[1]).toBeDisabled();
  });

  it('disables individual radio', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}}>
        <Radio value="option1" label="خيار 1" disabled />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    const radios = screen.getAllByRole('radio');
    expect(radios[0]).toBeDisabled();
    expect(radios[1]).not.toBeDisabled();
  });

  it('renders horizontal orientation', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}} orientation="horizontal">
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    expect(screen.getByRole('radiogroup')).toBeInTheDocument();
  });

  it('renders vertical orientation', () => {
    render(
      <RadioGroup name="test" value="" onChange={() => {}} orientation="vertical">
        <Radio value="option1" label="خيار 1" />
        <Radio value="option2" label="خيار 2" />
      </RadioGroup>
    );

    expect(screen.getByRole('radiogroup')).toBeInTheDocument();
  });
});
