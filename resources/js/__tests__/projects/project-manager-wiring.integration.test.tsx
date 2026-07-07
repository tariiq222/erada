/**
 * Integration test: ProjectForm → useProjectForm → BasicInfoStep wiring.
 *
 * Background — the bug that escaped:
 *   The project-create form gained an "I am the project manager" checkbox
 *   (i18n key `projects.i_am_the_manager`) wired to a manager picker.
 *   Unchecking the box must reveal the picker AND trigger a lazy fetch of
 *   assignable managers via `projectsApi.getAssignableManagers(type)`.
 *
 *   `ProjectForm.tsx` originally forgot to forward the assignable-manager
 *   state setters (`isSelfManager`, `setIsSelfManager`, `assignedManagerId`,
 *   `setAssignedManagerId`, `assignableManagers`, `isLoadingAssignableManagers`)
 *   to `BasicInfoStep`. With those props missing, `BasicInfoStep` defaults
 *   `setIsSelfManager` to `undefined` and the checkbox's onChange becomes a
 *   silent no-op (`setIsSelfManager?.(e.target.checked)`). Clicking the box
 *   did nothing — no state flip, no picker, no API call.
 *
 *   Every existing project-form test mocked `@pages/projects/form` (which
 *   stubbed `BasicInfoStep`) and mocked `useProjectForm`, so the wiring
 *   between them was never exercised. This test closes that gap by mounting
 *   the REAL `ProjectForm` + REAL `useProjectForm` + REAL `BasicInfoStep` and
 *   asserting the end-to-end behavior.
 */
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
  window.scrollTo = vi.fn();
});

// --- React Router: simulate ?type=development with no :id (create mode) ---
const mockNavigate = vi.fn();
const mockSetSearchParams = vi.fn();
const mockSearchParams = new URLSearchParams('type=development');
vi.mock('react-router-dom', () => ({
  useParams: () => ({}),
  useSearchParams: () => [mockSearchParams, mockSetSearchParams],
  useNavigate: () => mockNavigate,
  useLocation: () => ({ state: null }),
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => (
    <a href={to}>{children}</a>
  ),
}));

// --- i18n: keys we actually assert on are Arabic; the rest fall back to the key itself ---
vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    // The two assertions of this test
    'projects.i_am_the_manager': 'أنا مدير هذا المشروع',
    'projects.i_am_the_manager_hint': 'عند التفعيل، أنت مدير المشروع تلقائياً',
    'projects.assign_manager': 'تعيين مدير المشروع',
    'projects.assign_manager_placeholder': 'ابحث عن مستخدم واختره...',
    'projects.no_assignable_managers': 'لا يوجد مستخدمون مؤهلون',
    'projects.loading_managers': 'جاري التحميل...',
    // ProjectForm chrome
    'projects.new_project': 'مشروع جديد',
    'projects.title': 'المشاريع',
    'projects.create': 'إنشاء مشروع',
    'projects.update_data': 'تحديث بيانات المشروع',
    'projects.project_autosave_ready': 'الحفظ التلقائي جاهز',
    'projects.project_autosave_saving': 'جاري الحفظ...',
    'projects.project_autosave_saved': 'تم الحفظ',
    'projects.project_autosave_saved_at': 'تم الحفظ في {{time}}',
    'projects.project_autosave_restored': 'تمت استعادة المسودة',
    'projects.project_autosave_error': 'تعذّر الحفظ التلقائي',
    'projects.project_autosave_hint': 'يتم حفظ المسودة تلقائياً',
    'projects.improvement_autosave_ready': 'جاهز',
    'projects.improvement_autosave_saving': 'جاري الحفظ...',
    'projects.improvement_autosave_saved': 'تم الحفظ',
    'projects.improvement_autosave_saved_at': 'تم الحفظ في {{time}}',
    'projects.improvement_autosave_restored': 'تمت استعادة المسودة',
    'projects.improvement_autosave_error': 'تعذّر الحفظ التلقائي',
    'projects.improvement_autosave_hint': 'يتم حفظ المسودة تلقائياً',
    'projects.section_nav_label': 'التنقل بين الأقسام',
    'projects.step_basic_info': 'المعلومات الأساسية',
    'projects.pmbok_charter_fields': 'ميثاق المشروع',
    'projects.step_objectives_scope': 'الأهداف والنطاق',
    'projects.team': 'الفريق',
    'projects.stakeholders': 'أصحاب المصلحة',
    'projects.milestones': 'المراحل',
    'projects.tasks': 'المهام',
    'projects.risks': 'المخاطر',
    'projects.resources_and_support': 'الموارد والدعم',
    // Charter fields
    'projects.business_case': 'مبرر المشروع',
    'projects.business_case_placeholder': 'اكتب مبرر المشروع...',
    'projects.business_case_help': '',
    'projects.manager_authority': 'صلاحيات مدير المشروع',
    'projects.manager_authority_placeholder': 'اكتب صلاحيات المدير...',
    'projects.manager_authority_help': '',
    'projects.success_criteria': 'معايير النجاح',
    'projects.success_criteria_placeholder': 'اكتب معايير النجاح...',
    'projects.success_criteria_help': '',
    'projects.requirements': 'المتطلبات',
    'projects.requirements_placeholder': 'اكتب المتطلبات...',
    'projects.requirements_help': '',
    'projects.approval_criteria': 'معايير القبول',
    'projects.approval_criteria_placeholder': 'اكتب معايير القبول...',
    'projects.approval_criteria_help': '',
    'projects.exit_criteria': 'معايير الإغلاق',
    'projects.exit_criteria_placeholder': 'اكتب معايير الإغلاق...',
    'projects.exit_criteria_help': '',
    // BasicInfoStep fields
    'projects.name': 'اسم المشروع',
    'projects.enter_project_name': 'أدخل اسم المشروع',
    'projects.department': 'الإدارة',
    'projects.select_department': 'اختر الإدارة',
    'projects.no_departments': 'لا توجد إدارات',
    'projects.program_optional': 'البرنامج (اختياري)',
    'projects.standalone_project': 'مشروع مستقل',
    'projects.description': 'الوصف',
    'projects.enter_description': 'أدخل الوصف',
    'projects.priority': 'الأولوية',
    'projects.start_date': 'تاريخ البداية',
    'projects.select_start_date': 'اختر تاريخ البداية',
    'projects.end_date': 'تاريخ النهاية',
    'projects.select_end_date': 'اختر تاريخ النهاية',
    'projects.budget_sar': 'الميزانية (ريال)',
    'projects.supervisor': 'المشرف',
    'projects.select_supervisor': 'اختر المشرف',
    // Common
    'common.status': 'الحالة',
    'common.cancel': 'إلغاء',
    'common.save_changes': 'حفظ التغييرات',
    'common.required': 'مطلوب',
    // Status + priority options used by Select
    'status.draft': 'مسودة',
    'status.planning': 'تخطيط',
    'status.in_progress': 'قيد التنفيذ',
    'status.on_hold': 'معلق',
    'status.completed': 'مكتمل',
    'status.cancelled': 'ملغى',
    'priority.low': 'منخفضة',
    'priority.medium': 'متوسطة',
    'priority.high': 'عالية',
    'priority.urgent': 'عاجلة',
    'priority.critical': 'حرجة',
  };
  return {
    useTranslation: () => ({
      t: (key: string, params?: Record<string, unknown>) => {
        const val = translations[key];
        if (val === undefined) return key;
        if (params) {
          return val.replace(/\{\{(\w+)\}\}/g, (_: string, k: string) => String(params[k] ?? `{{${k}}}`));
        }
        return val;
      },
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
  };
});

// --- Entity APIs: only the calls the hook fires on create-mode mount ---
const mockGetCreatableDepartments = vi.fn();
const mockGetAssignableManagers = vi.fn();
const mockGetListPrograms = vi.fn();
const mockGetListUsers = vi.fn();

vi.mock('@entities/project', () => ({
  projectsApi: {
    getCreatableDepartments: (...args: unknown[]) => mockGetCreatableDepartments(...args),
    getAssignableManagers: (...args: unknown[]) => mockGetAssignableManagers(...args),
  },
}));
vi.mock('@entities/hr', () => ({
  departmentsApi: {
    getHierarchy: vi.fn().mockResolvedValue({ all: [] }),
  },
}));
vi.mock('@entities/user', () => ({
  usersApi: { getList: (...args: unknown[]) => mockGetListUsers(...args) },
}));
vi.mock('@entities/strategy', () => ({
  programsApi: { getList: (...args: unknown[]) => mockGetListPrograms(...args) },
}));

// --- Auth + Toast ---
vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    canAccess: () => true,
    user: { id: 42, name: 'Creator', permissions: [] },
  }),
}));
vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

// ⚠️ DELIBERATELY NO vi.mock('@pages/projects/form') —
// we want REAL ProjectForm → REAL useProjectForm → REAL BasicInfoStep.
import { ProjectForm } from '@pages/projects/ProjectForm';

const FAKE_ASSIGNABLE_MANAGERS = [
  { id: 7, name: 'Lina', email: 'lina@example.test', job_title: 'PM', department_id: 2 },
  { id: 8, name: 'Khalid', email: 'k@example.test', job_title: null, department_id: null },
];

describe('ProjectForm ↔ useProjectForm ↔ BasicInfoStep: assignable-manager wiring', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockGetCreatableDepartments.mockResolvedValue({ all: [] });
    mockGetAssignableManagers.mockResolvedValue(FAKE_ASSIGNABLE_MANAGERS);
    mockGetListPrograms.mockResolvedValue([]);
    mockGetListUsers.mockResolvedValue([]);
  });

  it('on create (?type=development): checkbox is checked by default, picker is hidden, no lazy fetch yet', async () => {
    render(<ProjectForm />);

    // Wait for the form to mount and the initial on-mount fetches to resolve.
    const checkbox = await screen.findByRole('checkbox', { name: /أنا مدير هذا المشروع/ });
    expect(checkbox).toBeInTheDocument();
    expect(checkbox).toBeChecked();

    // Manager picker must NOT be rendered when isSelfManager is true.
    expect(screen.queryByText('تعيين مدير المشروع')).not.toBeInTheDocument();

    // The lazy assignable-managers fetch is keyed on isSelfManager===false, so it must
    // not have fired on mount. (This guards against a different bug shape — calling it
    // eagerly would also be a regression.)
    expect(mockGetAssignableManagers).not.toHaveBeenCalled();
  });

  it('unchecking the checkbox flips state: picker becomes visible AND getAssignableManagers("development") is called', async () => {
    const user = userEvent.setup();
    render(<ProjectForm />);

    // Sanity: starts in the expected default state.
    const checkbox = await screen.findByRole('checkbox', { name: /أنا مدير هذا المشروع/ });
    expect(checkbox).toBeChecked();
    expect(screen.queryByText('تعيين مدير المشروع')).not.toBeInTheDocument();
    expect(mockGetAssignableManagers).not.toHaveBeenCalled();

    // The user unchecks the "I am the project manager" box.
    await user.click(checkbox);

    // The wiring must propagate the flip down to BasicInfoStep, which conditionally
    // renders the manager picker. And useProjectForm must observe isSelfManager===false
    // and fire the lazy assignable-managers fetch with the current project type.
    await waitFor(() => {
      expect(screen.getByText('تعيين مدير المشروع')).toBeInTheDocument();
    });
    await waitFor(() => {
      expect(mockGetAssignableManagers).toHaveBeenCalledTimes(1);
    });
    expect(mockGetAssignableManagers).toHaveBeenCalledWith('development');
  });
});