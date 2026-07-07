import { describe, it, expect, vi, beforeEach, afterEach, beforeAll } from 'vitest';
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
import {IconSearch, IconMail, IconEye} from '@tabler/icons-react';

// Mock scrollIntoView which is not available in jsdom
beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

import {
  Button,
  Input,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  CardFooter,
  Badge,
  Modal,
  ModalHeader,
  ModalBody,
  ModalFooter,
  Textarea,
  Select,
  Alert,
  Checkbox,
  Switch,
  Progress,
  Skeleton,
  SkeletonText,
  SkeletonCard,
  SkeletonTable,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
} from '@shared/ui';

describe('Button Component', () => {
  it('renders with children', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
  });

  it('renders with primary variant by default', () => {
    render(<Button>Primary</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-[var(--accent-hover)]');
  });

  it('renders with secondary variant', () => {
    render(<Button variant="secondary">Secondary</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-[var(--surface-muted)]');
  });

  it('renders with outline variant', () => {
    render(<Button variant="outline">Outline</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('border-[var(--border-default)]');
  });

  it('renders with ghost variant', () => {
    render(<Button variant="ghost">Ghost</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-transparent');
  });

  it('renders with danger variant', () => {
    render(<Button variant="danger">Danger</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('bg-[var(--status-danger)]');
  });

  it('renders with sm size', () => {
    render(<Button size="sm">Small</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('h-8');
  });

  it('renders with md size by default', () => {
    render(<Button>Medium</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('rounded-lg');
    expect(button).toHaveClass('px-4');
  });

  it('shows loading state', () => {
    render(<Button loading>Loading</Button>);
    const button = screen.getByRole('button');
    expect(button).toBeDisabled();
    expect(button.querySelector('svg')).toHaveClass('animate-spin');
  });

  it('renders with left icon', () => {
    render(<Button leftIcon={<IconSearch data-testid="left-icon" />}>IconSearch</Button>);
    expect(screen.getByTestId('left-icon')).toBeInTheDocument();
  });

  it('renders with right icon', () => {
    render(<Button rightIcon={<IconSearch data-testid="right-icon" />}>IconSearch</Button>);
    expect(screen.getByTestId('right-icon')).toBeInTheDocument();
  });

  it('does not show right icon when loading', () => {
    render(<Button loading rightIcon={<IconSearch data-testid="right-icon" />}>Loading</Button>);
    expect(screen.queryByTestId('right-icon')).not.toBeInTheDocument();
  });

  it('can be disabled', () => {
    render(<Button disabled>Disabled</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('calls onClick when clicked', async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Click</Button>);
    await userEvent.click(screen.getByRole('button'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('does not call onClick when disabled', async () => {
    const onClick = vi.fn();
    render(<Button disabled onClick={onClick}>Disabled</Button>);
    await userEvent.click(screen.getByRole('button'));
    expect(onClick).not.toHaveBeenCalled();
  });
});

describe('Input Component', () => {
  it('renders with placeholder', () => {
    render(<Input placeholder="Enter text" />);
    expect(screen.getByPlaceholderText('Enter text')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Input label="Email" placeholder="Enter email" />);
    expect(screen.getByText('Email')).toBeInTheDocument();
  });

  it('shows required indicator when required', () => {
    render(<Input label="Email" required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders with error message', () => {
    render(<Input error="This field is required" />);
    expect(screen.getByText('This field is required')).toBeInTheDocument();
  });

  it('renders with hint text', () => {
    render(<Input hint="Enter your email address" />);
    expect(screen.getByText('Enter your email address')).toBeInTheDocument();
  });

  it('shows error over hint', () => {
    render(<Input hint="Hint text" error="Error text" />);
    expect(screen.getByText('Error text')).toBeInTheDocument();
    expect(screen.queryByText('Hint text')).not.toBeInTheDocument();
  });

  it('renders with left icon', () => {
    render(<Input leftIcon={<IconMail data-testid="left-icon" />} />);
    expect(screen.getByTestId('left-icon')).toBeInTheDocument();
  });

  it('renders with right icon', () => {
    render(<Input rightIcon={<IconEye data-testid="right-icon" />} />);
    expect(screen.getByTestId('right-icon')).toBeInTheDocument();
  });

  it('calls onChange when typing', async () => {
    const onChange = vi.fn();
    render(<Input onChange={onChange} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello');
    expect(onChange).toHaveBeenCalled();
  });

  it('can be disabled', () => {
    render(<Input disabled />);
    expect(screen.getByRole('textbox')).toBeDisabled();
  });

  it('has aria-invalid when error is present', () => {
    render(<Input error="Error" />);
    expect(screen.getByRole('textbox')).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('Card Component', () => {
  it('renders children', () => {
    render(<Card>Card content</Card>);
    expect(screen.getByText('Card content')).toBeInTheDocument();
  });

  it('renders with default variant', () => {
    const { container } = render(<Card>Content</Card>);
    expect(container.firstChild).toHaveClass('shadow-sm');
  });

  it('renders with elevated variant', () => {
    const { container } = render(<Card variant="elevated">Content</Card>);
    expect(container.firstChild).toHaveClass('shadow-md');
  });

  it('renders with sm padding', () => {
    const { container } = render(<Card padding="sm">Content</Card>);
    expect(container.firstChild).toHaveClass('p-3');
  });

  it('renders with md padding by default', () => {
    const { container } = render(<Card>Content</Card>);
    expect(container.firstChild).toHaveClass('p-4');
  });
});

describe('Card Sub-components', () => {
  it('renders CardHeader', () => {
    render(<CardHeader>Header</CardHeader>);
    expect(screen.getByText('Header')).toBeInTheDocument();
  });

  it('renders CardTitle', () => {
    render(<CardTitle>Title</CardTitle>);
    expect(screen.getByText('Title')).toBeInTheDocument();
  });

  it('renders CardDescription', () => {
    render(<CardDescription>Description</CardDescription>);
    expect(screen.getByText('Description')).toBeInTheDocument();
  });

  it('renders CardContent', () => {
    render(<CardContent>Content</CardContent>);
    expect(screen.getByText('Content')).toBeInTheDocument();
  });

  it('renders CardFooter', () => {
    render(<CardFooter>Footer</CardFooter>);
    expect(screen.getByText('Footer')).toBeInTheDocument();
  });

  it('renders full card structure', () => {
    render(
      <Card>
        <CardHeader>
          <CardTitle>My Card</CardTitle>
          <CardDescription>Card description</CardDescription>
        </CardHeader>
        <CardContent>Main content</CardContent>
        <CardFooter>Footer content</CardFooter>
      </Card>
    );
    expect(screen.getByText('My Card')).toBeInTheDocument();
    expect(screen.getByText('Card description')).toBeInTheDocument();
    expect(screen.getByText('Main content')).toBeInTheDocument();
    expect(screen.getByText('Footer content')).toBeInTheDocument();
  });
});

describe('Badge Component', () => {
  it('renders with children', () => {
    render(<Badge>New</Badge>);
    expect(screen.getByText('New')).toBeInTheDocument();
  });

  it('renders with default variant', () => {
    render(<Badge>Default</Badge>);
    expect(screen.getByText('Default')).toHaveClass('bg-[var(--surface-muted)]');
  });

  it('renders with success variant', () => {
    render(<Badge variant="success">Success</Badge>);
    expect(screen.getByText('Success')).toHaveClass('bg-[var(--status-success-subtle)]');
  });

  it('renders with warning variant', () => {
    render(<Badge variant="warning">Warning</Badge>);
    expect(screen.getByText('Warning')).toHaveClass('bg-[var(--status-warning-subtle)]');
  });

  it('renders with danger variant', () => {
    render(<Badge variant="danger">Danger</Badge>);
    expect(screen.getByText('Danger')).toHaveClass('bg-[var(--status-danger-subtle)]');
  });

  it('renders with accent variant', () => {
    render(<Badge variant="accent">Accent</Badge>);
    expect(screen.getByText('Accent')).toHaveClass('bg-[var(--accent-subtle)]');
  });

  it('renders with sm size', () => {
    render(<Badge size="sm">Small</Badge>);
    expect(screen.getByText('Small')).toHaveClass('px-2');
  });

  it('renders with md size by default', () => {
    render(<Badge>Medium</Badge>);
    expect(screen.getByText('Medium')).toHaveClass('px-2');
  });
});

describe('Modal Component', () => {
  beforeEach(() => {
    // Create a div for portal
    const portalRoot = document.createElement('div');
    portalRoot.setAttribute('id', 'portal-root');
    document.body.appendChild(portalRoot);
  });

  afterEach(() => {
    const portalRoot = document.getElementById('portal-root');
    if (portalRoot) {
      document.body.removeChild(portalRoot);
    }
    document.body.style.overflow = '';
  });

  it('renders nothing when closed', () => {
    render(<Modal open={false} onClose={() => {}}>Content</Modal>);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders when open is true', () => {
    render(<Modal open={true} onClose={() => {}}>Content</Modal>);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('renders when isOpen is true (alias)', () => {
    render(<Modal isOpen={true} onClose={() => {}}>Content</Modal>);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('renders with title', () => {
    render(<Modal open={true} onClose={() => {}} title="My Modal">Content</Modal>);
    expect(screen.getByText('My Modal')).toBeInTheDocument();
  });

  it('calls onClose when close button clicked', async () => {
    const onClose = vi.fn();
    render(<Modal open={true} onClose={onClose} title="Modal">Content</Modal>);
    await userEvent.click(screen.getByLabelText('common.close'));
    expect(onClose).toHaveBeenCalled();
  });

  it('calls onClose when Escape pressed', () => {
    const onClose = vi.fn();
    render(<Modal open={true} onClose={onClose}>Content</Modal>);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
  });

  it('does not call onClose on Escape when closeOnEscape is false', () => {
    const onClose = vi.fn();
    render(<Modal open={true} onClose={onClose} closeOnEscape={false}>Content</Modal>);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).not.toHaveBeenCalled();
  });

  it('renders with different sizes', () => {
    const { rerender } = render(<Modal open={true} onClose={() => {}} size="sm">Content</Modal>);
    expect(screen.getByRole('dialog')).toHaveClass('sm:max-w-sm');

    rerender(<Modal open={true} onClose={() => {}} size="lg">Content</Modal>);
    expect(screen.getByRole('dialog')).toHaveClass('sm:max-w-2xl');
  });
});

describe('ModalHeader, ModalBody, ModalFooter', () => {
  it('renders ModalHeader with children', () => {
    render(<ModalHeader>Header Content</ModalHeader>);
    expect(screen.getByText('Header Content')).toBeInTheDocument();
  });

  it('renders ModalHeader with close button', async () => {
    const onClose = vi.fn();
    render(<ModalHeader onClose={onClose} showCloseButton>Title</ModalHeader>);
    await userEvent.click(screen.getByLabelText('common.close'));
    expect(onClose).toHaveBeenCalled();
  });

  it('renders ModalHeader without close button', () => {
    render(<ModalHeader showCloseButton={false}>Title</ModalHeader>);
    expect(screen.queryByLabelText('common.close')).not.toBeInTheDocument();
  });

  it('renders ModalBody with children', () => {
    render(<ModalBody>Body Content</ModalBody>);
    expect(screen.getByText('Body Content')).toBeInTheDocument();
  });

  it('renders ModalFooter with children', () => {
    render(<ModalFooter>Footer Content</ModalFooter>);
    expect(screen.getByText('Footer Content')).toBeInTheDocument();
  });
});

describe('Textarea Component', () => {
  it('renders with placeholder', () => {
    render(<Textarea placeholder="Enter description" />);
    expect(screen.getByPlaceholderText('Enter description')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Textarea label="Description" />);
    expect(screen.getByText('Description')).toBeInTheDocument();
  });

  it('renders with error', () => {
    render(<Textarea error="Required field" />);
    expect(screen.getByText('Required field')).toBeInTheDocument();
  });

  it('renders with hint', () => {
    render(<Textarea hint="Max 500 characters" />);
    expect(screen.getByText('Max 500 characters')).toBeInTheDocument();
  });

  it('can be disabled', () => {
    render(<Textarea disabled />);
    expect(screen.getByRole('textbox')).toBeDisabled();
  });
});

describe('Select Component', () => {
  const options = [
    { value: '1', label: 'Option 1' },
    { value: '2', label: 'Option 2' },
    { value: '3', label: 'Option 3' },
  ];

  it('renders with placeholder', () => {
    render(<Select options={options} placeholder="Select option" />);
    expect(screen.getByText('Select option')).toBeInTheDocument();
  });

  it('renders with label', () => {
    render(<Select options={options} label="Category" />);
    expect(screen.getByText('Category')).toBeInTheDocument();
  });

  it('renders options in listbox', async () => {
    render(<Select options={options} />);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button'));

    // Options are mounted in a listbox after the select is opened.
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    options.forEach(opt => {
      expect(screen.getByRole('option', { name: opt.label })).toBeInTheDocument();
    });
  });

  it('calls onChange when option clicked', async () => {
    const onChange = vi.fn();
    render(<Select options={options} onChange={onChange} />);

    // Click the trigger button
    const trigger = screen.getByRole('button');
    await userEvent.click(trigger);

    // Click an option
    await userEvent.click(screen.getByRole('option', { name: 'Option 2' }));
    expect(onChange).toHaveBeenCalledWith({ target: { value: '2' } });
  });

  it('renders with error', () => {
    render(<Select options={options} error="Required" />);
    expect(screen.getByText('Required')).toBeInTheDocument();
  });

  it('can be disabled', () => {
    render(<Select options={options} disabled />);
    const trigger = screen.getByRole('button');
    expect(trigger).toBeDisabled();
  });

  it('shows selected value', () => {
    render(<Select options={options} value="2" onChange={() => {}} />);
    // The button should display the selected value
    const trigger = screen.getByRole('button');
    expect(trigger).toHaveTextContent('Option 2');
  });

  it('renders hint text', () => {
    render(<Select options={options} hint="Select your category" />);
    expect(screen.getByText('Select your category')).toBeInTheDocument();
  });
});

describe('Alert Component', () => {
  it('renders with children', () => {
    render(<Alert>Alert message</Alert>);
    expect(screen.getByText('Alert message')).toBeInTheDocument();
  });

  it('renders with title', () => {
    render(<Alert title="Alert Title">Content</Alert>);
    expect(screen.getByText('Alert Title')).toBeInTheDocument();
  });

  it('renders with different variants', () => {
    const { rerender, container } = render(<Alert variant="info">Info</Alert>);
    // info uses accent-subtle
    expect(container.firstChild).toHaveClass('bg-[var(--accent-subtle)]');

    rerender(<Alert variant="success">Success</Alert>);
    expect(container.firstChild).toHaveClass('bg-[var(--status-success-subtle)]');

    rerender(<Alert variant="warning">Warning</Alert>);
    expect(container.firstChild).toHaveClass('bg-[var(--status-warning-subtle)]');

    rerender(<Alert variant="danger">Danger</Alert>);
    expect(container.firstChild).toHaveClass('bg-[var(--status-danger-subtle)]');
  });

  it('renders with dismissible button', async () => {
    const onDismiss = vi.fn();
    render(<Alert dismissible onDismiss={onDismiss}>Dismissible</Alert>);
    await userEvent.click(screen.getByLabelText('Dismiss'));
    expect(onDismiss).toHaveBeenCalled();
  });
});

describe('Checkbox Component', () => {
  it('renders with label', () => {
    render(<Checkbox label="Accept terms" />);
    expect(screen.getByText('Accept terms')).toBeInTheDocument();
  });

  it('renders checked state', () => {
    render(<Checkbox checked onChange={() => {}} />);
    expect(screen.getByRole('checkbox')).toBeChecked();
  });

  it('renders unchecked state', () => {
    render(<Checkbox checked={false} onChange={() => {}} />);
    expect(screen.getByRole('checkbox')).not.toBeChecked();
  });

  it('calls onChange when clicked', async () => {
    const onChange = vi.fn();
    render(<Checkbox onChange={onChange} />);
    await userEvent.click(screen.getByRole('checkbox'));
    expect(onChange).toHaveBeenCalled();
  });

  it('can be disabled', () => {
    render(<Checkbox disabled />);
    expect(screen.getByRole('checkbox')).toBeDisabled();
  });

  it('renders with description', () => {
    render(<Checkbox label="Terms" description="Accept our terms and conditions" />);
    expect(screen.getByText('Accept our terms and conditions')).toBeInTheDocument();
  });
});

describe('Switch Component', () => {
  it('renders with label', () => {
    render(<Switch label="Enable notifications" />);
    expect(screen.getByText('Enable notifications')).toBeInTheDocument();
  });

  it('renders checked state', () => {
    render(<Switch checked onChange={() => {}} />);
    expect(screen.getByRole('switch')).toBeChecked();
  });

  it('calls onChange when toggled', async () => {
    const onChange = vi.fn();
    render(<Switch onChange={onChange} />);
    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalled();
  });

  it('can be disabled', () => {
    render(<Switch disabled />);
    expect(screen.getByRole('switch')).toBeDisabled();
  });
});

describe('Progress Component', () => {
  it('renders with value', () => {
    render(<Progress value={50} />);
    const progressBar = screen.getByTestId('progress-fill');
    expect(progressBar).toHaveStyle({ width: '50%' });
  });

  it('renders with 0 value', () => {
    render(<Progress value={0} />);
    const progressBar = screen.getByTestId('progress-fill');
    expect(progressBar).toHaveStyle({ width: '0%' });
  });

  it('renders with 100 value', () => {
    render(<Progress value={100} />);
    const progressBar = screen.getByTestId('progress-fill');
    expect(progressBar).toHaveStyle({ width: '100%' });
  });

  it('clamps value to 0-100 range', () => {
    const { rerender } = render(<Progress value={-10} />);
    let progressBar = screen.getByTestId('progress-fill');
    expect(progressBar).toHaveStyle({ width: '0%' });

    rerender(<Progress value={150} />);
    progressBar = screen.getByTestId('progress-fill');
    expect(progressBar).toHaveStyle({ width: '100%' });
  });

  it('renders with different sizes', () => {
    const { rerender } = render(<Progress value={50} size="sm" />);
    expect(screen.getByRole('progressbar')).toHaveClass('h-1.5');

    rerender(<Progress value={50} size="md" />);
    expect(screen.getByRole('progressbar')).toHaveClass('h-2');
  });

  it('renders with showValue', () => {
    render(<Progress value={75} showValue />);
    expect(screen.getByText('75%')).toBeInTheDocument();
  });

  it('has correct aria attributes', () => {
    render(<Progress value={50} />);
    const progressBar = screen.getByRole('progressbar');
    expect(progressBar).toHaveAttribute('aria-valuenow', '50');
    expect(progressBar).toHaveAttribute('aria-valuemin', '0');
    expect(progressBar).toHaveAttribute('aria-valuemax', '100');
  });
});

describe('Skeleton Component', () => {
  it('renders basic skeleton', () => {
    const { container } = render(<Skeleton />);
    expect(container.firstChild).toHaveClass('animate-pulse');
  });

  it('renders with custom className', () => {
    const { container } = render(<Skeleton className="h-10 w-full" />);
    expect(container.firstChild).toHaveClass('h-10', 'w-full');
  });

  it('renders with text variant', () => {
    const { container } = render(<Skeleton variant="text" />);
    expect(container.firstChild).toHaveClass('rounded');
  });

  it('renders with circular variant', () => {
    const { container } = render(<Skeleton variant="circular" />);
    expect(container.firstChild).toHaveClass('rounded-full');
  });

  it('renders with rectangular variant', () => {
    const { container } = render(<Skeleton variant="rectangular" />);
    expect(container.firstChild).toBeInTheDocument();
  });

  it('renders with rounded variant', () => {
    const { container } = render(<Skeleton variant="rounded" />);
    expect(container.firstChild).toHaveClass('rounded-lg');
  });

  it('renders with custom width and height as numbers', () => {
    const { container } = render(<Skeleton width={100} height={50} />);
    expect(container.firstChild).toHaveStyle({ width: '100px', height: '50px' });
  });

  it('renders with custom width and height as strings', () => {
    const { container } = render(<Skeleton width="50%" height="2rem" />);
    expect(container.firstChild).toHaveStyle({ width: '50%', height: '2rem' });
  });

  it('renders with no animation', () => {
    const { container } = render(<Skeleton animation="none" />);
    expect(container.firstChild).not.toHaveClass('animate-pulse');
  });

  it('renders with wave animation (same as pulse)', () => {
    const { container } = render(<Skeleton animation="wave" />);
    expect(container.firstChild).toHaveClass('animate-pulse');
  });
});

describe('SkeletonText Component', () => {
  it('renders with default 3 lines', () => {
    render(<SkeletonText />);
    expect(screen.getAllByTestId('skeleton').length).toBe(3);
  });

  it('renders with custom number of lines', () => {
    render(<SkeletonText lines={5} />);
    expect(screen.getAllByTestId('skeleton').length).toBe(5);
  });

  it('applies custom className', () => {
    const { container } = render(<SkeletonText className="my-4" />);
    expect(container.firstChild).toHaveClass('my-4');
  });
});

describe('SkeletonCard Component', () => {
  it('renders with image by default', () => {
    render(<SkeletonCard />);
    // Image placeholder + title + 2 lines + 2 buttons = 6 skeletons
    expect(screen.getAllByTestId('skeleton').length).toBeGreaterThan(0);
  });

  it('renders without image when showImage is false', () => {
    const { container } = render(<SkeletonCard showImage={false} />);
    expect(container.firstChild).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<SkeletonCard className="w-full" />);
    expect(container.firstChild).toHaveClass('w-full');
  });
});

describe('SkeletonTable Component', () => {
  it('renders with default 5 rows and 4 columns', () => {
    render(<SkeletonTable />);
    // Header (4) + 5*4 body = 24 skeletons
    expect(screen.getAllByTestId('skeleton').length).toBe(24);
  });

  it('renders with custom rows and columns', () => {
    render(<SkeletonTable rows={3} columns={2} />);
    // Header (2) + 3*2 body = 8 skeletons
    expect(screen.getAllByTestId('skeleton').length).toBe(8);
  });

  it('applies custom className', () => {
    const { container } = render(<SkeletonTable className="max-w-lg" />);
    expect(container.firstChild).toHaveClass('max-w-lg');
  });
});

describe('Tabs Component', () => {
  it('renders tabs with triggers and content', () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">Tab 1</TabsTrigger>
          <TabsTrigger value="tab2">Tab 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">Content 1</TabsContent>
        <TabsContent value="tab2">Content 2</TabsContent>
      </Tabs>
    );

    expect(screen.getByText('Tab 1')).toBeInTheDocument();
    expect(screen.getByText('Tab 2')).toBeInTheDocument();
    expect(screen.getByText('Content 1')).toBeInTheDocument();
  });

  it('switches tab content when clicking trigger', async () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">Tab 1</TabsTrigger>
          <TabsTrigger value="tab2">Tab 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">Content 1</TabsContent>
        <TabsContent value="tab2">Content 2</TabsContent>
      </Tabs>
    );

    await userEvent.click(screen.getByText('Tab 2'));
    expect(screen.getByText('Content 2')).toBeInTheDocument();
  });
});
