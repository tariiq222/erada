import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

// Import UI components
import { Drawer, DrawerHeader, DrawerBody, DrawerFooter } from '@shared/ui/Drawer';
import { Accordion, AccordionItem, AccordionTrigger, AccordionContent } from '@shared/ui/Accordion';
import { ToastProvider, useToast, ToastItem } from '@shared/ui/Toast';
import { Dropdown, DropdownTrigger, DropdownMenu, DropdownItem, DropdownSeparator } from '@shared/ui/Dropdown';

// ==================== Drawer Tests ====================
describe('Drawer Component', () => {
  it('renders when open', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <div>محتوى الـ Drawer</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('محتوى الـ Drawer')).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(
      <Drawer open={false} onClose={() => {}}>
        <div>محتوى الـ Drawer</div>
      </Drawer>
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('calls onClose when Escape key is pressed', () => {
    const onClose = vi.fn();
    render(
      <Drawer open={true} onClose={onClose}>
        <div>محتوى</div>
      </Drawer>
    );

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
  });

  it('does not close on Escape when closeOnEscape is false', () => {
    const onClose = vi.fn();
    render(
      <Drawer open={true} onClose={onClose} closeOnEscape={false}>
        <div>محتوى</div>
      </Drawer>
    );

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).not.toHaveBeenCalled();
  });

  it('renders with right position by default', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <div>محتوى</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('renders with left position', () => {
    render(
      <Drawer open={true} onClose={() => {}} position="left">
        <div>محتوى</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('renders different sizes', () => {
    const { rerender } = render(
      <Drawer open={true} onClose={() => {}} size="sm">
        <div>محتوى</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    rerender(
      <Drawer open={true} onClose={() => {}} size="lg">
        <div>محتوى</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    rerender(
      <Drawer open={true} onClose={() => {}} size="xl">
        <div>محتوى</div>
      </Drawer>
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });
});

describe('DrawerHeader Component', () => {
  it('renders children', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerHeader>عنوان الـ Drawer</DrawerHeader>
      </Drawer>
    );
    expect(screen.getByText('عنوان الـ Drawer')).toBeInTheDocument();
  });

  it('renders close button when showCloseButton is true', () => {
    const onClose = vi.fn();
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerHeader onClose={onClose} showCloseButton>
          عنوان
        </DrawerHeader>
      </Drawer>
    );
    expect(screen.getByLabelText('common.close')).toBeInTheDocument();
  });

  it('calls onClose when close button clicked', async () => {
    const onClose = vi.fn();
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerHeader onClose={onClose} showCloseButton>
          عنوان
        </DrawerHeader>
      </Drawer>
    );

    await userEvent.click(screen.getByLabelText('common.close'));
    expect(onClose).toHaveBeenCalled();
  });

  it('hides close button when showCloseButton is false', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerHeader showCloseButton={false}>عنوان</DrawerHeader>
      </Drawer>
    );
    expect(screen.queryByLabelText('Close')).not.toBeInTheDocument();
  });
});

describe('DrawerBody Component', () => {
  it('renders children', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerBody>محتوى الجسم</DrawerBody>
      </Drawer>
    );
    expect(screen.getByText('محتوى الجسم')).toBeInTheDocument();
  });
});

describe('DrawerFooter Component', () => {
  it('renders children', () => {
    render(
      <Drawer open={true} onClose={() => {}}>
        <DrawerFooter>
          <button>إلغاء</button>
          <button>حفظ</button>
        </DrawerFooter>
      </Drawer>
    );
    expect(screen.getByText('إلغاء')).toBeInTheDocument();
    expect(screen.getByText('حفظ')).toBeInTheDocument();
  });
});

// ==================== Accordion Tests ====================
describe('Accordion Component', () => {
  it('renders accordion with items', () => {
    render(
      <Accordion defaultValue="item-1">
        <AccordionItem value="item-1">
          <AccordionTrigger>عنوان 1</AccordionTrigger>
          <AccordionContent>محتوى 1</AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>عنوان 2</AccordionTrigger>
          <AccordionContent>محتوى 2</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    expect(screen.getByText('عنوان 1')).toBeInTheDocument();
    expect(screen.getByText('عنوان 2')).toBeInTheDocument();
  });

  it('expands default value item', () => {
    render(
      <Accordion defaultValue="item-1">
        <AccordionItem value="item-1">
          <AccordionTrigger>عنوان 1</AccordionTrigger>
          <AccordionContent>محتوى 1</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger = screen.getByText('عنوان 1').closest('button');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
  });

  it('toggles item on click', async () => {
    render(
      <Accordion defaultValue="">
        <AccordionItem value="item-1">
          <AccordionTrigger>عنوان 1</AccordionTrigger>
          <AccordionContent>محتوى 1</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger = screen.getByText('عنوان 1').closest('button')!;
    expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await userEvent.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
  });

  it('single type only expands one item', async () => {
    render(
      <Accordion type="single" defaultValue="item-1">
        <AccordionItem value="item-1">
          <AccordionTrigger>عنوان 1</AccordionTrigger>
          <AccordionContent>محتوى 1</AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>عنوان 2</AccordionTrigger>
          <AccordionContent>محتوى 2</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger1 = screen.getByText('عنوان 1').closest('button')!;
    const trigger2 = screen.getByText('عنوان 2').closest('button')!;

    expect(trigger1).toHaveAttribute('aria-expanded', 'true');
    expect(trigger2).toHaveAttribute('aria-expanded', 'false');

    await userEvent.click(trigger2);

    expect(trigger1).toHaveAttribute('aria-expanded', 'false');
    expect(trigger2).toHaveAttribute('aria-expanded', 'true');
  });

  it('multiple type allows multiple expanded items', async () => {
    render(
      <Accordion type="multiple" defaultValue={['item-1']}>
        <AccordionItem value="item-1">
          <AccordionTrigger>عنوان 1</AccordionTrigger>
          <AccordionContent>محتوى 1</AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>عنوان 2</AccordionTrigger>
          <AccordionContent>محتوى 2</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger1 = screen.getByText('عنوان 1').closest('button')!;
    const trigger2 = screen.getByText('عنوان 2').closest('button')!;

    await userEvent.click(trigger2);

    expect(trigger1).toHaveAttribute('aria-expanded', 'true');
    expect(trigger2).toHaveAttribute('aria-expanded', 'true');
  });

  it('renders with icon', () => {
    const TestIcon = () => <span data-testid="icon">📁</span>;
    render(
      <Accordion defaultValue="">
        <AccordionItem value="item-1">
          <AccordionTrigger icon={<TestIcon />}>عنوان</AccordionTrigger>
          <AccordionContent>محتوى</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('disables item when disabled', () => {
    render(
      <Accordion defaultValue="">
        <AccordionItem value="item-1" disabled>
          <AccordionTrigger>عنوان</AccordionTrigger>
          <AccordionContent>محتوى</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger = screen.getByText('عنوان').closest('button');
    expect(trigger).toBeDisabled();
  });
});

// ==================== Toast Tests ====================
describe('ToastProvider and useToast', () => {
  const ToastTestComponent = () => {
    const { addToast } = useToast();

    return (
      <button onClick={() => addToast({ variant: 'success', message: 'رسالة نجاح' })}>
        إظهار Toast
      </button>
    );
  };

  it('renders children', () => {
    render(
      <ToastProvider>
        <div>محتوى</div>
      </ToastProvider>
    );
    expect(screen.getByText('محتوى')).toBeInTheDocument();
  });

  it('shows toast when addToast is called', async () => {
    render(
      <ToastProvider>
        <ToastTestComponent />
      </ToastProvider>
    );

    await userEvent.click(screen.getByText('إظهار Toast'));
    // Announcements come from the aria-live container, not a per-item role="alert"
    expect(document.querySelector('[aria-live="polite"]')).toBeInTheDocument();
    expect(screen.getByText('رسالة نجاح')).toBeInTheDocument();
  });
});

describe('ToastItem Component', () => {
  const mockToast = {
    id: '1',
    variant: 'success' as const,
    message: 'تم الحفظ بنجاح',
  };

  it('renders toast message', () => {
    render(
      <ToastProvider>
        <ToastItem toast={mockToast} onClose={() => {}} />
      </ToastProvider>
    );
    expect(screen.getByText('تم الحفظ بنجاح')).toBeInTheDocument();
  });

  it('renders toast title when provided', () => {
    const toastWithTitle = {
      ...mockToast,
      title: 'نجاح',
    };
    render(
      <ToastProvider>
        <ToastItem toast={toastWithTitle} onClose={() => {}} />
      </ToastProvider>
    );
    expect(screen.getByText('نجاح')).toBeInTheDocument();
  });

  it('calls onClose when close button clicked', async () => {
    const onClose = vi.fn();
    render(
      <ToastProvider>
        <ToastItem toast={mockToast} onClose={onClose} />
      </ToastProvider>
    );

    await userEvent.click(screen.getByLabelText('Close'));
    expect(onClose).toHaveBeenCalled();
  });

  it('renders info variant', () => {
    const infoToast = { ...mockToast, variant: 'info' as const };
    render(
      <ToastProvider>
        <ToastItem toast={infoToast} onClose={() => {}} />
      </ToastProvider>
    );
    // Variant is signaled by its distinguishing icon + tinted container border
    const message = screen.getByText('تم الحفظ بنجاح');
    // kept: tests DOM layout the screen query API cannot reach
    const icon = message.parentElement!.parentElement!.firstElementChild!.firstElementChild;
    expect(message).toBeInTheDocument();
    expect(icon).toHaveClass('tabler-icon-info-circle');
  });

  it('renders warning variant', () => {
    const warningToast = { ...mockToast, variant: 'warning' as const };
    render(
      <ToastProvider>
        <ToastItem toast={warningToast} onClose={() => {}} />
      </ToastProvider>
    );
    const message = screen.getByText('تم الحفظ بنجاح');
    // kept: tests DOM layout the screen query API cannot reach
    const icon = message.parentElement!.parentElement!.firstElementChild!.firstElementChild;
    expect(message).toBeInTheDocument();
    expect(icon).toHaveClass('tabler-icon-alert-triangle');
  });

  it('renders error variant', () => {
    const errorToast = { ...mockToast, variant: 'error' as const };
    render(
      <ToastProvider>
        <ToastItem toast={errorToast} onClose={() => {}} />
      </ToastProvider>
    );
    const message = screen.getByText('تم الحفظ بنجاح');
    // kept: tests DOM layout the screen query API cannot reach
    const icon = message.parentElement!.parentElement!.firstElementChild!.firstElementChild;
    expect(message).toBeInTheDocument();
    expect(icon).toHaveClass('tabler-icon-alert-circle');
  });
});

// ==================== Dropdown Tests ====================
describe('Dropdown Component', () => {
  it('renders dropdown trigger', () => {
    render(
      <Dropdown>
        <DropdownTrigger>اختر خيار</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار 1</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );
    expect(screen.getByText('اختر خيار')).toBeInTheDocument();
  });

  it('opens menu when trigger clicked', async () => {
    render(
      <Dropdown>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار 1</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    await userEvent.click(screen.getByText('اختر'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(screen.getByText('خيار 1')).toBeInTheDocument();
  });

  it('closes menu when clicking outside', async () => {
    render(
      <div>
        <Dropdown>
          <DropdownTrigger>اختر</DropdownTrigger>
          <DropdownMenu>
            <DropdownItem value="1">خيار 1</DropdownItem>
          </DropdownMenu>
        </Dropdown>
        <button>خارج</button>
      </div>
    );

    await userEvent.click(screen.getByText('اختر'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    fireEvent.mouseDown(screen.getByText('خارج'));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('selects item and closes menu', async () => {
    const onValueChange = vi.fn();
    render(
      <Dropdown onValueChange={onValueChange}>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="option1">خيار 1</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    await userEvent.click(screen.getByText('اختر'));
    await userEvent.click(screen.getByText('خيار 1'));

    expect(onValueChange).toHaveBeenCalledWith('option1');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('shows placeholder when no value selected', () => {
    render(
      <Dropdown>
        <DropdownTrigger placeholder="اختر خيار...">
          {null}
        </DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار 1</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );
    expect(screen.getByText('اختر خيار...')).toBeInTheDocument();
  });

  it('renders item with icon', async () => {
    const TestIcon = () => <span data-testid="item-icon">🏠</span>;
    render(
      <Dropdown>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1" icon={<TestIcon />}>خيار</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    await userEvent.click(screen.getByText('اختر'));
    expect(screen.getByTestId('item-icon')).toBeInTheDocument();
  });

  it('renders separator', async () => {
    render(
      <Dropdown>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار 1</DropdownItem>
          <DropdownSeparator />
          <DropdownItem value="2">خيار 2</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    await userEvent.click(screen.getByText('اختر'));
    expect(screen.getByText('خيار 1')).toBeInTheDocument();
    expect(screen.getByText('خيار 2')).toBeInTheDocument();
  });

  it('shows check mark for selected item', async () => {
    render(
      <Dropdown value="option1">
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="option1">خيار 1</DropdownItem>
          <DropdownItem value="option2">خيار 2</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    await userEvent.click(screen.getByText('اختر'));

    const selectedOption = screen.getByRole('option', { name: /خيار 1/ });
    expect(selectedOption).toHaveAttribute('aria-selected', 'true');
  });

  it('has correct aria attributes on trigger', () => {
    render(
      <Dropdown>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    const trigger = screen.getByText('اختر').closest('button');
    expect(trigger).toHaveAttribute('aria-haspopup', 'listbox');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  it('updates aria-expanded when opened', async () => {
    render(
      <Dropdown>
        <DropdownTrigger>اختر</DropdownTrigger>
        <DropdownMenu>
          <DropdownItem value="1">خيار</DropdownItem>
        </DropdownMenu>
      </Dropdown>
    );

    const triggerButton = screen.getByText('اختر').closest('button')!;
    expect(triggerButton).toHaveAttribute('aria-expanded', 'false');

    await userEvent.click(triggerButton);
    expect(triggerButton).toHaveAttribute('aria-expanded', 'true');
  });
});
