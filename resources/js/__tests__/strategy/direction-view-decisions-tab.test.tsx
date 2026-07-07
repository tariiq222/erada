import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import React from 'react';

vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'common.overview': 'نظرة عامة',
    'common.edit': 'تعديل',
    'common.description': 'الوصف',
    'common.details': 'التفاصيل',
    'common.start_date': 'تاريخ البداية',
    'common.end_date': 'تاريخ النهاية',
    'common.view_link': 'عرض الرابط',
    'common.view': 'عرض',
    'common.status': 'الحالة',
    'common.priority': 'الأولوية',
    'common.total': 'الإجمالي',
    'common.active': 'نشط',
    'status.completed': 'مكتمل',
    'strategy.executive_planning': 'التخطيط التنفيذي',
    'strategy.portfolios': 'الالتزامات',
    'strategy.programs': 'المبادرات',
    'strategy.program': 'المبادرة',
    'strategy.program_manager': 'مدير المبادرة',
    'strategy.completion': 'نسبة الإنجاز',
    'strategy.completion_rate': 'نسبة الإنجاز',
    'strategy.projects': 'المشاريع',
    'strategy.objectives': 'الأهداف',
    'strategy.overall_completion': 'نسبة الإنجاز الإجمالية',
    'strategy.portfolio_progress': 'تقدم الالتزام',
    'strategy.programs_summary': 'ملخص المبادرات',
    'strategy.directive_source': 'جهة التوجيه',
    'strategy.strategic_plan': 'الخطة الاستراتيجية',
    'strategy.no_linked_programs': 'لا توجد مبادرات مرتبطة',
    'strategy.no_linked_programs_desc': 'لم يتم ربط أي مبادرة بهذا الالتزام بعد.',
    'strategy.create_new_program': 'إنشاء مبادرة جديدة',
    'strategy.portfolio_load_error': 'فشل تحميل الالتزام',
    'strategy.portfolio_not_found': 'الالتزام غير موجود',
    'strategy.back_to_portfolios': 'العودة للالتزامات',
    'status.draft': 'مسودة',
    'strategy.decisions.title': 'القرارات',
    'strategy.decisions.list.new_button': 'قرار جديد',
    'strategy.decisions.form.create_title': 'إنشاء قرار',
    'meetings.decision.section.header': 'قرارات {{name}}',
    'meetings.decision.section.empty': 'لا توجد قرارات',
    'meetings.decision.section.create_cta': 'إنشاء قرار',
    'meetings.decision.section.view_all': 'عرض الكل',
  };
  return {
    useTranslation: () => ({
      t: (key: string, params?: Record<string, string>) => {
        const val = translations[key];
        if (val === undefined) return key;
        if (!params) return val;
        return val.replace(/\{\{(\w+)\}\}/g, (_: string, k: string) => params[k] ?? `{{${k}}}`);
      },
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
    Trans: ({ i18nKey }: { i18nKey: string }) => translations[i18nKey] ?? i18nKey,
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: '4' }),
  useSearchParams: () => [new URLSearchParams(), vi.fn()],
  useNavigate: () => vi.fn(),
  Link: ({ children, to, className }: { children: React.ReactNode; to: string; className?: string }) => (
    <a href={to} className={className}>{children}</a>
  ),
}));

vi.mock('@entities/strategy', () => ({
  portfoliosApi: {
    getOne: vi.fn().mockResolvedValue({
      id: 4,
      code: 'PF-2026-004',
      name: 'الالتزام الاستراتيجي للتحول',
      description: 'وصف الالتزام',
      status: 'active',
      status_label: 'نشط',
      strategic_plan_link: null,
      directive_source: 'royal',
      directive_source_other: null,
      directive_source_label: 'توجيه ملكي',
      start_date: '2026-01-01',
      end_date: '2026-12-31',
      order: 1,
      objectives_count: 5,
      programs_count: 3,
      progress: 42,
    }),
  },
  programsApi: {
    getAll: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

const mockAuthState: { permissions: string[] } = {
  permissions: ['view_strategy', 'create_strategy', 'edit_strategy'],
};

// Phase 9.3: project the legacy `mockAuthState.permissions[]` into the
// canonical `access` shape that production code now reads via useCan.
function projectAccess(): Record<string, Record<string, boolean>> {
  const perms = mockAuthState.permissions;
  if (perms.includes('edit_strategy')) {
    return { strategy: { view: true, create: true, edit: true } };
  }
  if (perms.length > 0) {
    return { strategy: { view: true } };
  }
  return {};
}

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    user: {
      id: 1,
      name: 'مستخدم',
      access: projectAccess(),
    },
    hasPermission: (p: string) => mockAuthState.permissions.includes(p),
    canAccess: () => true,
    isAdmin: () => false,
    isSuperAdmin: () => false,
  }),
}));

vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,
    IconEdit: () => <span data-testid="icon-edit" />,
    IconCalendar: () => <span data-testid="icon-calendar" />,
    IconBuilding: () => <span data-testid="icon-building" />,
    IconTarget: () => <span data-testid="icon-target" />,
    IconLayoutKanban: () => <span data-testid="icon-layout-kanban" />,
    IconTrendingUp: () => <span data-testid="icon-trending-up" />,
    IconBriefcase: () => <span data-testid="icon-briefcase" />,
    IconEye: () => <span data-testid="icon-eye" />,
    IconFileText: () => <span data-testid="icon-file-text" />,
    IconLink: () => <span data-testid="icon-link" />,
  };
});

vi.mock('@shared/ui/icons', () => ({
  IconClipboardCheck: () => <span data-testid="icon-clipboard-check" />,
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

vi.mock('@shared/ui', async (importOriginal) => ({
  ...((await importOriginal()) as Record<string, unknown>),
  Button: ({ children, leftIcon, onClick }: {
    children?: React.ReactNode;
    leftIcon?: React.ReactNode;
    onClick?: () => void;
  }) => <button onClick={onClick}>{leftIcon}{children}</button>,
  Tabs: ({ children, defaultValue }: { children: React.ReactNode; defaultValue?: string }) => (
    <div data-testid="tabs" data-default={defaultValue}>{children}</div>
  ),
  TabsList: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="tabs-list">{children}</div>
  ),
  TabsTrigger: ({ children, value, icon }: { children: React.ReactNode; value: string; icon?: React.ReactNode }) => (
    <button data-testid={`tab-${value}`}>{icon}{children}</button>
  ),
  TabsContent: ({ children, value }: { children: React.ReactNode; value: string }) => (
    <div data-testid={`content-${value}`}>{children}</div>
  ),
  Breadcrumb: ({ items }: { items: Array<{ label: string; href?: string }> }) => (
    <nav data-testid="breadcrumb">
      {items.map((item, i) => <span key={i}>{item.label}</span>)}
    </nav>
  ),
  Badge: ({ children }: { children: React.ReactNode }) => <span data-testid="badge">{children}</span>,
  StatusBadge: () => <span data-testid="status-badge" />,
  Skeleton: () => <span data-testid="skeleton" />,
  Card: ({ children }: { children: React.ReactNode }) => <div data-testid="card">{children}</div>,
  CardContent: ({ children }: { children: React.ReactNode }) => <div data-testid="card-content">{children}</div>,
  PageHeader: ({ title, actions }: { title: React.ReactNode; actions?: React.ReactNode }) => (
    <div data-testid="page-header">
      <h1>{title}</h1>
      {actions}
    </div>
  ),
  StatCard: ({ label }: { label: string }) => <div data-testid="stat-card">{label}</div>,
  StatStrip: ({ items }: { items: Array<{ label: string; value: number }> }) => (
    <div data-testid="stat-strip">{items.length} items</div>
  ),
  Avatar: ({ name }: { name: string }) => <span data-testid="avatar">{name}</span>,
  Progress: ({ value }: { value: number }) => <div data-testid="progress" data-value={value} />,
  Table: ({ children }: { children: React.ReactNode }) => <table data-testid="table">{children}</table>,
  TableHeader: ({ children }: { children: React.ReactNode }) => <thead data-testid="table-header">{children}</thead>,
  TableBody: ({ children }: { children: React.ReactNode }) => <tbody data-testid="table-body">{children}</tbody>,
  TableHead: ({ children }: { children: React.ReactNode }) => <th data-testid="table-head">{children}</th>,
  TableRow: ({ children }: { children: React.ReactNode }) => <tr data-testid="table-row">{children}</tr>,
  TableCell: ({ children }: { children: React.ReactNode }) => <td data-testid="table-cell">{children}</td>,
}));

interface CapturedDecisionsProps {
  decidable_type: string;
  decidable_id: number;
  decidable_name: string;
  permissions: { canView: boolean; canCreate: boolean; canEdit: boolean };
}

let lastDecisionsProps: CapturedDecisionsProps | null = null;

vi.mock('@features/meetings', () => ({
  DecisionsSection: (props: CapturedDecisionsProps) => {
    lastDecisionsProps = props;
    return (
      <div
        data-testid="decisions-section"
        data-decidable-type={props.decidable_type}
        data-decidable-id={props.decidable_id}
        data-decidable-name={props.decidable_name}
        data-can-view={String(props.permissions.canView)}
        data-can-create={String(props.permissions.canCreate)}
        data-can-edit={String(props.permissions.canEdit)}
      />
    );
  },
}));

import DirectionView from '@pages/strategy/portfolios/DirectionView';

describe('DirectionView — Decisions tab integration (portfolio)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    lastDecisionsProps = null;
    mockAuthState.permissions = ['view_strategy', 'create_strategy', 'edit_strategy'];
  });

  it('renders a TabsTrigger with value="decisions" labeled with strategy.decisions.title', async () => {
    render(<DirectionView />);
    await waitFor(() => {
      expect(screen.getByTestId('tab-decisions')).toBeInTheDocument();
    });
    const trigger = screen.getByTestId('tab-decisions');
    expect(trigger).toHaveTextContent('القرارات');
    expect(screen.getByTestId('icon-clipboard-check')).toBeInTheDocument();
  });

  it('renders a TabsContent with value="decisions" containing the DecisionsSection', async () => {
    render(<DirectionView />);
    await waitFor(() => {
      expect(screen.getByTestId('content-decisions')).toBeInTheDocument();
    });
    expect(screen.getByTestId('decisions-section')).toBeInTheDocument();
  });

  it('passes decidable_type="portfolio", portfolio id and name to DecisionsSection', async () => {
    render(<DirectionView />);
    await waitFor(() => {
      expect(lastDecisionsProps).not.toBeNull();
    });
    expect(lastDecisionsProps!.decidable_type).toBe('portfolio');
    expect(lastDecisionsProps!.decidable_id).toBe(4);
    expect(lastDecisionsProps!.decidable_name).toBe('الالتزام الاستراتيجي للتحول');
  });

  it('wires permissions from useAuth().hasPermission() (view_strategy / create_strategy / edit_strategy)', async () => {
    render(<DirectionView />);
    await waitFor(() => {
      expect(lastDecisionsProps).not.toBeNull();
    });
    expect(lastDecisionsProps!.permissions).toEqual({
      canView: true,
      canCreate: true,
      canEdit: true,
    });
  });

  it('reflects restricted permissions when the user lacks strategy permissions', async () => {
    mockAuthState.permissions = [];

    render(<DirectionView />);
    await waitFor(() => {
      expect(lastDecisionsProps).not.toBeNull();
    });
    expect(lastDecisionsProps!.permissions).toEqual({
      canView: false,
      canCreate: false,
      canEdit: false,
    });
  });
});
