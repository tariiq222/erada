import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

// Mock LocaleContext (DesignSystem uses useLocale for direction)
vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ direction: 'rtl', locale: 'ar', setLocale: vi.fn(), toggleLocale: vi.fn() }),
}));

// Mock UI components
vi.mock('@shared/ui', () => ({
  Button: ({ children, onClick, variant, size, leftIcon, rightIcon, loading, disabled }: any) => (
    <button onClick={onClick} disabled={disabled || loading} data-variant={variant} data-size={size}>
      {leftIcon}
      {loading ? 'جاري التحميل' : children}
      {rightIcon}
    </button>
  ),
  Input: ({ label, placeholder, leftIcon, error, hint, disabled, type, value }: any) => (
    <div>
      {label && <label>{label}</label>}
      {leftIcon}
      <input placeholder={placeholder} disabled={disabled} type={type} defaultValue={value} />
      {error && <span className="error">{error}</span>}
      {hint && <span className="hint">{hint}</span>}
    </div>
  ),
  Textarea: ({ label, placeholder, error, hint }: any) => (
    <div>
      {label && <label>{label}</label>}
      <textarea placeholder={placeholder} />
      {error && <span className="error">{error}</span>}
      {hint && <span className="hint">{hint}</span>}
    </div>
  ),
  Select: ({ label, placeholder, options, error }: any) => (
    <div>
      {label && <label>{label}</label>}
      <select>
        <option value="">{placeholder}</option>
        {options?.map((opt: any) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
      </select>
      {error && <span className="error">{error}</span>}
    </div>
  ),
  Card: ({ children, variant, className }: any) => (
    <div data-testid="card" data-variant={variant} className={className}>{children}</div>
  ),
  CardHeader: ({ children, className }: any) => <div className={className}>{children}</div>,
  CardTitle: ({ children }: any) => <h3>{children}</h3>,
  CardDescription: ({ children }: any) => <p>{children}</p>,
  CardContent: ({ children, className }: any) => <div className={className}>{children}</div>,
  CardFooter: ({ children }: any) => <div>{children}</div>,
  Badge: ({ children, variant, size }: any) => (
    <span data-testid="badge" data-variant={variant} data-size={size}>{children}</span>
  ),
  Avatar: ({ name, src, size, status }: any) => (
    <div data-testid="avatar" data-size={size} data-status={status}>{name}</div>
  ),
  Tabs: ({ children, defaultValue }: any) => <div data-testid="tabs" data-default={defaultValue}>{children}</div>,
  TabsList: ({ children, className }: any) => <div className={className} role="tablist">{children}</div>,
  TabsTrigger: ({ children, value, icon }: any) => (
    <button role="tab" data-value={value}>{icon}{children}</button>
  ),
  TabsContent: ({ children, value }: any) => <div role="tabpanel" data-value={value}>{children}</div>,
  Accordion: ({ children, type, defaultValue }: any) => (
    <div data-testid="accordion" data-type={type}>{children}</div>
  ),
  AccordionItem: ({ children, value }: any) => <div data-value={value}>{children}</div>,
  AccordionTrigger: ({ children, icon }: any) => <button>{icon}{children}</button>,
  AccordionContent: ({ children }: any) => <div>{children}</div>,
  Modal: ({ children, open, onClose, size }: any) => (
    open ? <div data-testid="modal" data-size={size}>{children}</div> : null
  ),
  ModalHeader: ({ children, onClose }: any) => (
    <div>
      <h2>{children}</h2>
      <button onClick={onClose} aria-label="إغلاق">X</button>
    </div>
  ),
  ModalBody: ({ children }: any) => <div>{children}</div>,
  ModalFooter: ({ children }: any) => <div>{children}</div>,
  Drawer: ({ children, open, onClose, position }: any) => (
    open ? <div data-testid="drawer" data-position={position}>{children}</div> : null
  ),
  DrawerHeader: ({ children, onClose }: any) => (
    <div>
      <h2>{children}</h2>
      <button onClick={onClose} aria-label="إغلاق">X</button>
    </div>
  ),
  DrawerBody: ({ children }: any) => <div>{children}</div>,
  DrawerFooter: ({ children }: any) => <div>{children}</div>,
  Table: ({ children, striped, hoverable }: any) => (
    <table data-striped={striped} data-hoverable={hoverable}>{children}</table>
  ),
  TableHeader: ({ children }: any) => <thead>{children}</thead>,
  TableBody: ({ children }: any) => <tbody>{children}</tbody>,
  TableHead: ({ children, sortable, sortDirection }: any) => (
    <th data-sortable={sortable} data-sort={sortDirection}>{children}</th>
  ),
  TableRow: ({ children }: any) => <tr>{children}</tr>,
  TableCell: ({ children, className }: any) => <td className={className}>{children}</td>,
  Pagination: ({ currentPage, totalPages, onPageChange }: any) => (
    <div data-testid="pagination">
      <button onClick={() => onPageChange(currentPage - 1)} disabled={currentPage === 1}>السابق</button>
      <span>{currentPage} / {totalPages}</span>
      <button onClick={() => onPageChange(currentPage + 1)} disabled={currentPage === totalPages}>التالي</button>
    </div>
  ),
  Progress: ({ value, size, showValue }: any) => (
    <div data-testid="progress" data-value={value} data-size={size}>
      {showValue && <span>{value}%</span>}
    </div>
  ),
  Checkbox: ({ label, description, checked, onChange, disabled, indeterminate }: any) => (
    <label>
      <input type="checkbox" checked={checked} onChange={onChange} disabled={disabled} />
      {label}
      {description && <span>{description}</span>}
    </label>
  ),
  RadioGroup: ({ children, name, value, onChange }: any) => (
    <div data-testid="radio-group" data-name={name}>{children}</div>
  ),
  Radio: ({ value, label, description }: any) => (
    <label>
      <input type="radio" value={value} />
      {label}
      {description && <span>{description}</span>}
    </label>
  ),
  Switch: ({ label, description, checked, onChange, disabled, size }: any) => (
    <label data-testid="switch">
      <input type="checkbox" role="switch" checked={checked} onChange={onChange} disabled={disabled} />
      {label}
      {description && <span>{description}</span>}
    </label>
  ),
  Alert: ({ children, variant, title, dismissible }: any) => (
    <div data-testid="alert" data-variant={variant}>
      {title && <strong>{title}</strong>}
      {children}
      {dismissible && <button aria-label="إغلاق">X</button>}
    </div>
  ),
  Tooltip: ({ children, content, position }: any) => (
    <div data-testid="tooltip" title={content} data-position={position}>{children}</div>
  ),
  Dropdown: ({ children }: any) => <div data-testid="dropdown">{children}</div>,
  DropdownTrigger: ({ children }: any) => <button>{children}</button>,
  DropdownMenu: ({ children }: any) => <div role="menu">{children}</div>,
  DropdownItem: ({ children, value, icon }: any) => (
    <button role="menuitem" data-value={value}>{icon}{children}</button>
  ),
  Breadcrumb: ({ items, className }: any) => (
    <nav data-testid="breadcrumb" className={className}>
      {items.map((item: any, i: number) => (
        <span key={i}>{item.href ? <a href={item.href}>{item.label}</a> : item.label}</span>
      ))}
    </nav>
  ),
  SkeletonText: ({ lines }: any) => (
    <div data-testid="skeleton-text">{Array(lines).fill(0).map((_, i) => <div key={i} />)}</div>
  ),
  SkeletonCard: () => <div data-testid="skeleton-card" />,
  ToastProvider: ({ children }: any) => <div data-testid="toast-provider">{children}</div>,
  useToast: () => ({
    addToast: vi.fn(),
  }),
}));

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconSearch: () => <span data-testid="search-icon">Search</span>,
  IconPlus: () => <span data-testid="plus-icon">Plus</span>,
  IconSettings: () => <span data-testid="settings-icon">Settings</span>,
  IconMail: () => <span data-testid="mail-icon">Mail</span>,
  IconLock: () => <span data-testid="lock-icon">Lock</span>,
  IconFolder: () => <span data-testid="folder-icon">Folder</span>,
  IconFileText: () => <span data-testid="file-icon">FileText</span>,
  IconCalendar: () => <span data-testid="calendar-icon">Calendar</span>,
  IconBell: () => <span data-testid="bell-icon">Bell</span>,

  };
});

import DesignSystem from '@pages/DesignSystem';

describe('DesignSystem Page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders page title', () => {
    render(<DesignSystem />);
    expect(screen.getByText('نظام التصميم الموحد')).toBeInTheDocument();
  });

  it('renders version badge', () => {
    render(<DesignSystem />);
    expect(screen.getByText('v1.3')).toBeInTheDocument();
  });

  it('renders breadcrumb', () => {
    render(<DesignSystem />);
    expect(screen.getByTestId('breadcrumb')).toBeInTheDocument();
    expect(screen.getByText('التوثيق')).toBeInTheDocument();
    expect(screen.getByText('نظام التصميم')).toBeInTheDocument();
  });

  it('renders tabs navigation', () => {
    render(<DesignSystem />);
    expect(screen.getByText('الأزرار')).toBeInTheDocument();
    expect(screen.getByText('الحقول')).toBeInTheDocument();
    expect(screen.getByText('البطاقات')).toBeInTheDocument();
    expect(screen.getByText('التبويبات')).toBeInTheDocument();
  });
});

describe('DesignSystem Buttons Section', () => {
  it('renders button variants section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('أنماط الأزرار')).toBeInTheDocument();
  });

  it('shows different button variants', () => {
    render(<DesignSystem />);
    // أساسي appears multiple times
    expect(screen.getAllByText('أساسي').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('ثانوي')).toBeInTheDocument();
    expect(screen.getByText('محدد')).toBeInTheDocument();
    expect(screen.getByText('شفاف')).toBeInTheDocument();
    // خطر appears multiple times
    expect(screen.getAllByText('خطر').length).toBeGreaterThanOrEqual(1);
  });

  it('shows button with specific variant', () => {
    render(<DesignSystem />);
    // نجاح appears multiple times
    expect(screen.getAllByText('نجاح').length).toBeGreaterThanOrEqual(1);
  });

  it('renders button sizes section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('أحجام الأزرار')).toBeInTheDocument();
    expect(screen.getByText('صغير')).toBeInTheDocument();
    expect(screen.getByText('متوسط')).toBeInTheDocument();
  });

  it('renders buttons with icons section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('أزرار مع أيقونات')).toBeInTheDocument();
    expect(screen.getByText('إضافة جديد')).toBeInTheDocument();
    // الإعدادات appears in multiple places
    expect(screen.getAllByText('الإعدادات').length).toBeGreaterThanOrEqual(1);
  });

  it('shows disabled and loading buttons', () => {
    render(<DesignSystem />);
    expect(screen.getByText('معطل')).toBeInTheDocument();
    expect(screen.getByText('جاري التحميل')).toBeInTheDocument();
  });
});

describe('DesignSystem Inputs Section', () => {
  it('renders input fields section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('حقول الإدخال')).toBeInTheDocument();
  });

  it('shows various input types', () => {
    render(<DesignSystem />);
    expect(screen.getByText('الاسم الكامل')).toBeInTheDocument();
    expect(screen.getByText('البريد الإلكتروني')).toBeInTheDocument();
    expect(screen.getByText('كلمة المرور')).toBeInTheDocument();
    expect(screen.getByText('البحث')).toBeInTheDocument();
  });

  it('shows input with error', () => {
    render(<DesignSystem />);
    expect(screen.getByText('حقل به خطأ')).toBeInTheDocument();
    expect(screen.getByText('هذا الحقل مطلوب')).toBeInTheDocument();
  });

  it('shows disabled input', () => {
    render(<DesignSystem />);
    expect(screen.getByText('حقل معطل')).toBeInTheDocument();
  });

  it('renders textarea section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('منطقة النص')).toBeInTheDocument();
    expect(screen.getByText('الوصف')).toBeInTheDocument();
  });

  it('renders select section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('القوائم المنسدلة')).toBeInTheDocument();
  });
});

describe('DesignSystem Cards Section', () => {
  it('renders card variants', () => {
    render(<DesignSystem />);
    expect(screen.getByText('بطاقة افتراضية')).toBeInTheDocument();
    expect(screen.getByText('بطاقة محددة')).toBeInTheDocument();
    expect(screen.getByText('بطاقة مرتفعة')).toBeInTheDocument();
  });

  it('shows card with footer', () => {
    render(<DesignSystem />);
    expect(screen.getByText('عرض المزيد')).toBeInTheDocument();
  });
});

describe('DesignSystem Tabs Section', () => {
  it('renders nested tabs examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('تبويبات افتراضية')).toBeInTheDocument();
    expect(screen.getByText('التبويب الأول')).toBeInTheDocument();
    expect(screen.getByText('التبويب الثاني')).toBeInTheDocument();
  });

  it('shows tabs with icons', () => {
    render(<DesignSystem />);
    expect(screen.getByText('تبويبات مع أيقونات')).toBeInTheDocument();
    expect(screen.getByText('المشاريع')).toBeInTheDocument();
    expect(screen.getByText('المهام')).toBeInTheDocument();
    expect(screen.getByText('التقويم')).toBeInTheDocument();
  });
});

describe('DesignSystem Accordion Section', () => {
  it('renders accordion examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('أكورديون فردي')).toBeInTheDocument();
    expect(screen.getByText('أكورديون متعدد')).toBeInTheDocument();
  });

  it('shows accordion items', () => {
    render(<DesignSystem />);
    expect(screen.getByText('ما هو نظام إدارة المشاريع؟')).toBeInTheDocument();
    expect(screen.getByText('كيف يمكنني إنشاء مشروع جديد؟')).toBeInTheDocument();
  });

  it('shows accordion with icons', () => {
    render(<DesignSystem />);
    expect(screen.getByText('إدارة المشاريع')).toBeInTheDocument();
    expect(screen.getByText('تتبع المهام')).toBeInTheDocument();
    expect(screen.getByText('الإشعارات')).toBeInTheDocument();
  });
});

describe('DesignSystem Modal and Drawer', () => {
  it('renders modal trigger button', () => {
    render(<DesignSystem />);
    expect(screen.getByText('فتح نافذة منبثقة')).toBeInTheDocument();
  });

  it('renders drawer trigger button', () => {
    render(<DesignSystem />);
    expect(screen.getByText('فتح درج جانبي')).toBeInTheDocument();
  });

  it('opens modal on click', () => {
    render(<DesignSystem />);

    const openButton = screen.getByText('فتح نافذة منبثقة');
    fireEvent.click(openButton);

    expect(screen.getByTestId('modal')).toBeInTheDocument();
    expect(screen.getByText('إضافة مشروع جديد')).toBeInTheDocument();
  });

  it('opens drawer on click', () => {
    render(<DesignSystem />);

    const openButton = screen.getByText('فتح درج جانبي');
    fireEvent.click(openButton);

    expect(screen.getByTestId('drawer')).toBeInTheDocument();
    expect(screen.getByText('تفاصيل المشروع')).toBeInTheDocument();
  });

  it('closes modal', () => {
    render(<DesignSystem />);

    const openButton = screen.getByText('فتح نافذة منبثقة');
    fireEvent.click(openButton);

    expect(screen.getByTestId('modal')).toBeInTheDocument();

    const closeButton = screen.getAllByText('إلغاء')[0];
    fireEvent.click(closeButton);

    expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
  });

  it('closes drawer', () => {
    render(<DesignSystem />);

    const openButton = screen.getByText('فتح درج جانبي');
    fireEvent.click(openButton);

    expect(screen.getByTestId('drawer')).toBeInTheDocument();

    const closeButton = screen.getByText('إغلاق');
    fireEvent.click(closeButton);

    expect(screen.queryByTestId('drawer')).not.toBeInTheDocument();
  });
});

describe('DesignSystem Tables Section', () => {
  it('renders table section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('جدول المشاريع')).toBeInTheDocument();
  });

  it('shows table headers', () => {
    render(<DesignSystem />);
    expect(screen.getByText('اسم المشروع')).toBeInTheDocument();
    // الحالة appears multiple times in the page
    expect(screen.getAllByText('الحالة').length).toBeGreaterThanOrEqual(1);
    // التقدم appears multiple times
    expect(screen.getAllByText('التقدم').length).toBeGreaterThanOrEqual(1);
  });

  it('shows table rows', () => {
    render(<DesignSystem />);
    expect(screen.getByText('تطوير منصة التجارة الإلكترونية')).toBeInTheDocument();
    expect(screen.getByText('تحديث نظام الموارد البشرية')).toBeInTheDocument();
    expect(screen.getByText('إطلاق تطبيق الجوال')).toBeInTheDocument();
  });

  it('renders pagination', () => {
    render(<DesignSystem />);
    expect(screen.getByTestId('pagination')).toBeInTheDocument();
  });

  it('handles pagination click', () => {
    render(<DesignSystem />);

    const nextButton = screen.getByText('التالي');
    fireEvent.click(nextButton);

    // Page should change
    expect(screen.getByText('2 / 10')).toBeInTheDocument();
  });
});

describe('DesignSystem Forms Section', () => {
  it('renders checkbox examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('صناديق الاختيار')).toBeInTheDocument();
    expect(screen.getByText('أوافق على الشروط والأحكام')).toBeInTheDocument();
  });

  it('renders radio button examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('أزرار الراديو')).toBeInTheDocument();
    expect(screen.getByText('الخيار الأول')).toBeInTheDocument();
    expect(screen.getByText('الخيار الثاني')).toBeInTheDocument();
  });

  it('renders switch examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('مفاتيح التبديل')).toBeInTheDocument();
    expect(screen.getByText('الوضع الليلي')).toBeInTheDocument();
  });

  it('renders progress bar examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('شريط التقدم')).toBeInTheDocument();
  });
});

describe('DesignSystem Feedback Section', () => {
  it('renders alert examples', () => {
    render(<DesignSystem />);
    // التنبيهات appears multiple times
    expect(screen.getAllByText('التنبيهات').length).toBeGreaterThanOrEqual(1);
  });

  it('shows different alert types', () => {
    render(<DesignSystem />);
    // معلومة appears multiple times (in title and toast button)
    expect(screen.getAllByText('معلومة').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('تم بنجاح')).toBeInTheDocument();
    // تحذير appears multiple times
    expect(screen.getAllByText('تحذير').length).toBeGreaterThanOrEqual(1);
    // خطأ appears multiple times
    expect(screen.getAllByText('خطأ').length).toBeGreaterThanOrEqual(1);
  });

  it('renders toast section', () => {
    render(<DesignSystem />);
    expect(screen.getByText('رسائل Toast')).toBeInTheDocument();
  });

  it('renders tooltip examples', () => {
    render(<DesignSystem />);
    // التلميحات appears multiple times
    expect(screen.getAllByText('التلميحات').length).toBeGreaterThanOrEqual(1);
    // أعلى appears multiple times
    expect(screen.getAllByText('أعلى').length).toBeGreaterThanOrEqual(1);
    // أسفل appears multiple times
    expect(screen.getAllByText('أسفل').length).toBeGreaterThanOrEqual(1);
  });
});

describe('DesignSystem Misc Section', () => {
  it('renders badge examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('الشارات')).toBeInTheDocument();
    expect(screen.getByText('افتراضي')).toBeInTheDocument();
    // نجاح appears multiple times
    expect(screen.getAllByText('نجاح').length).toBeGreaterThanOrEqual(1);
  });

  it('renders avatar examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('الصور الرمزية')).toBeInTheDocument();
    expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
    expect(screen.getByText('سارة أحمد')).toBeInTheDocument();
  });

  it('renders skeleton examples', () => {
    render(<DesignSystem />);
    expect(screen.getByText('هياكل التحميل')).toBeInTheDocument();
    expect(screen.getByTestId('skeleton-text')).toBeInTheDocument();
    expect(screen.getByTestId('skeleton-card')).toBeInTheDocument();
  });
});

describe('DesignSystem Dropdown', () => {
  it('renders dropdown example', () => {
    render(<DesignSystem />);
    expect(screen.getByText('القوائم المنسدلة المخصصة')).toBeInTheDocument();
    expect(screen.getByText('اختر إجراء')).toBeInTheDocument();
  });

  it('shows dropdown menu items', () => {
    render(<DesignSystem />);
    expect(screen.getByText('تعديل')).toBeInTheDocument();
    expect(screen.getByText('نسخ')).toBeInTheDocument();
  });
});
