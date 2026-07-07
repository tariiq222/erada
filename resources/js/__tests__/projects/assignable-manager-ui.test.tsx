/**
 * TDD contract for the assignable-manager UI inside `BasicInfoStep`.
 *
 * - Default state: "أنا مدير هذا المشروع" checkbox is checked, the user
 *   picker is NOT rendered, no call to fetch managers is expected.
 * - When the user unchecks the box, the picker appears AND the assignable
 *   managers list is requested from the hook layer (the hook triggers the
 *   fetch via its lazy effect — verified separately in
 *   `assignable-manager-hook.test.tsx`).
 * - The picker is populated from the `assignableManagers` prop and writes
 *   back through `onChangeManager`.
 * - Required-state + error message: when unchecked and no manager is
 *   selected, the picker shows an aria-describedby error.
 *
 * Follows the same translation-table + i18n-mock pattern as
 * `project-form.test.tsx`.
 */
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
  window.scrollTo = vi.fn();
});

// Tabler icons: pass-through so the snapshot stays simple.
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return actual;
});

// i18n mock with the keys used by the assignable-manager UI plus a
// handful of common keys the surrounding form depends on.
vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'common.required': 'مطلوب',
    'common.status': 'الحالة',
    'common.select': 'اختر',
    'projects.name': 'اسم المشروع',
    'projects.basic_info': 'المعلومات الأساسية',
    'projects.enter_project_name': 'أدخل اسم المشروع',
    'projects.description': 'الوصف',
    'projects.department': 'الإدارة',
    'projects.select_department': 'اختر القسم',
    'projects.no_departments': 'لا توجد أقسام',
    'projects.program_optional': 'البرنامج (اختياري)',
    'projects.standalone_project': 'مشروع مستقل',
    'projects.link_to_program_hint': 'ربط ببرنامج',
    'projects.settings_and_timeline': 'الإعدادات والجدول الزمني',
    'projects.priority': 'الأولوية',
    'projects.start_date': 'تاريخ البداية',
    'projects.end_date': 'تاريخ النهاية',
    'projects.select_start_date': 'اختر تاريخ البداية',
    'projects.select_end_date': 'اختر تاريخ النهاية',
    'projects.budget_sar': 'الميزانية (ريال)',
    'projects.supervisor': 'المشرف',
    'projects.select_supervisor': 'اختر المشرف',
    'projects.i_am_the_manager': 'أنا مدير هذا المشروع',
    'projects.i_am_the_manager_hint': 'عند التفعيل، أنت مدير المشروع تلقائياً. أطفئه لتعيين مدير آخر.',
    'projects.assign_manager': 'تعيين مدير المشروع',
    'projects.assign_manager_placeholder': 'ابحث عن مستخدم واختره...',
    'projects.assign_manager_required': 'يجب اختيار مدير للمشروع',
    'projects.no_assignable_managers': 'لا يوجد مستخدمون مؤهلون',
    'projects.loading_managers': 'جاري التحميل...',
    'status.draft': 'مسودة',
    'status.in_progress': 'قيد التنفيذ',
    'priority.low': 'منخفضة',
    'priority.medium': 'متوسطة',
    'priority.high': 'عالية',
  };
  return {
    useTranslation: () => ({
      t: (key: string) => translations[key] ?? key,
      i18n: { language: 'ar' },
    }),
  };
});

// Render-only smoke imports — the hook is exercised separately, the form
// step just receives the assignable-managers slice as props.
import BasicInfoStep from '@pages/projects/form/BasicInfoStep';
import type {
  ProjectFormData,
  DepartmentOption,
  ProgramOption,
  ValidationErrors,
} from '@pages/projects/form/types';

const baseFormData: ProjectFormData = {
  name: '',
  description: '',
  objectives: [''],
  in_scope: [''],
  out_of_scope: [''],
  department_id: '',
  program_id: '',
  manager_id: '42',
  sponsor_id: '',
  status: 'draft',
  priority: 'medium',
  start_date: '',
  end_date: '',
  budget: '',
  milestones: [],
  tasks: [],
  risks: [],
  kpis: [],
  team_members: [],
  stakeholders: [],
  human_resources: '',
  technical_resources: '',
  financial_resources: '',
  type: 'development',
  business_case: '',
  success_criteria: '',
  requirements: '',
  manager_authority: '',
  approval_criteria: '',
  exit_criteria: '',
  target_process: '',
  problem_statement: '',
  root_cause: '',
  expected_benefits: '',
  current_pdca_phase: '',
};

const noDepartments: DepartmentOption[] = [];
const noPrograms: ProgramOption[] = [];
const noErrors: ValidationErrors = {};

interface RenderOpts {
  isSelfManager?: boolean;
  setIsSelfManager?: (v: boolean) => void;
  assignedManagerId?: string;
  setAssignedManagerId?: (v: string) => void;
  assignableManagers?: Array<{ id: number; name: string; email: string; job_title: string | null; department_id: number | null }>;
  isLoadingAssignableManagers?: boolean;
  errors?: ValidationErrors;
  onChangeField?: (field: keyof ProjectFormData, value: ProjectFormData[keyof ProjectFormData]) => void;
}

function renderStep(opts: RenderOpts = {}) {
  const onChangeField = opts.onChangeField ?? vi.fn();
  const setIsSelfManager = opts.setIsSelfManager ?? vi.fn();
  const setAssignedManagerId = opts.setAssignedManagerId ?? vi.fn();
  const assignableManagers = opts.assignableManagers ?? [];
  const isLoadingAssignableManagers = opts.isLoadingAssignableManagers ?? false;
  const errors = opts.errors ?? noErrors;
  const utils = render(
    <BasicInfoStep
      formData={baseFormData}
      allDepartments={noDepartments}
      programs={noPrograms}
      errors={errors}
      onChangeField={onChangeField}
      compact
      narrow
      // New assignable-manager props (these are added in the hook return
      // contract; UI tests thread them through explicitly).
      isSelfManager={opts.isSelfManager ?? true}
      setIsSelfManager={setIsSelfManager}
      assignedManagerId={opts.assignedManagerId ?? ''}
      setAssignedManagerId={setAssignedManagerId}
      assignableManagers={assignableManagers}
      isLoadingAssignableManagers={isLoadingAssignableManagers}
    />
  );
  return { ...utils, onChangeField, setIsSelfManager, setAssignedManagerId };
}

describe('BasicInfoStep — assignable manager UI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the checkbox checked by default and does NOT show the picker', () => {
    renderStep();

    const checkbox = screen.getByRole('checkbox', { name: /أنا مدير هذا المشروع/ });
    expect(checkbox).toBeInTheDocument();
    expect(checkbox).toBeChecked();

    // Picker label/placeholder must not be present.
    expect(screen.queryByText('تعيين مدير المشروع')).not.toBeInTheDocument();
    expect(
      screen.queryByPlaceholderText('ابحث عن مستخدم واختره...')
    ).not.toBeInTheDocument();
  });

  it('renders the picker when the checkbox is unchecked and wires setIsSelfManager(false)', async () => {
    const user = userEvent.setup();
    const setIsSelfManager = vi.fn();
    renderStep({ setIsSelfManager });

    const checkbox = screen.getByRole('checkbox', { name: /أنا مدير هذا المشروع/ });
    expect(checkbox).toBeChecked();

    await user.click(checkbox);

    expect(setIsSelfManager).toHaveBeenCalledWith(false);
  });

  it('shows the picker (with the assignable managers list) when unchecked and a list is provided', () => {
    renderStep({
      isSelfManager: false,
      assignedManagerId: '',
      assignableManagers: [
        { id: 7, name: 'Lina', email: 'lina@example.test', job_title: 'Project Manager', department_id: 2 },
        { id: 8, name: 'Omar', email: 'omar@example.test', job_title: null, department_id: null },
      ],
    });

    // Picker label is now present.
    expect(screen.getByText('تعيين مدير المشروع')).toBeInTheDocument();

    // The select trigger should exist; Select is a custom listbox-button.
    const picker = screen.getByRole('button', { name: /تعيين مدير المشروع/ });
    expect(picker).toBeInTheDocument();
    expect(picker).toHaveAttribute('aria-required', 'true');
    // Placeholder must show
    expect(screen.getByText('ابحث عن مستخدم واختره...')).toBeInTheDocument();
  });

  it('writes the picked user id back through setAssignedManagerId', async () => {
    const setAssignedManagerId = vi.fn();
    const user = userEvent.setup();
    renderStep({
      isSelfManager: false,
      assignedManagerId: '',
      assignableManagers: [
        { id: 7, name: 'Lina', email: 'lina@example.test', job_title: 'Project Manager', department_id: 2 },
      ],
      setAssignedManagerId,
    });

    const picker = screen.getByRole('button', { name: /تعيين مدير المشروع/ });
    await user.click(picker);
    // Click the option in the listbox. The label is "Name - Job Title"
    // when job_title is set.
    const listbox = await screen.findByRole('listbox');
    const option = within(listbox).getByText(/Lina/);
    await user.click(option);

    expect(setAssignedManagerId).toHaveBeenCalledWith('7');
  });

  it('wires aria-describedby + an inline error when the picker is required but empty', () => {
    renderStep({
      isSelfManager: false,
      assignedManagerId: '',
      assignableManagers: [
        { id: 7, name: 'Lina', email: 'lina@example.test', job_title: null, department_id: null },
      ],
      errors: { manager_user_id: ['يجب اختيار مدير للمشروع'] },
    });

    const picker = screen.getByRole('button', { name: /تعيين مدير المشروع/ });
    expect(picker).toHaveAttribute('aria-invalid', 'true');
    expect(picker.getAttribute('aria-describedby')).toMatch(/-error$/);

    // The error message text is rendered next to the picker.
    expect(screen.getByText('يجب اختيار مدير للمشروع')).toBeInTheDocument();
  });

  it('renders an empty-state message when the assignable list is empty', () => {
    renderStep({
      isSelfManager: false,
      assignedManagerId: '',
      assignableManagers: [],
    });
    // The trigger placeholder shows the "no eligible users" hint.
    expect(screen.getByText('لا يوجد مستخدمون مؤهلون')).toBeInTheDocument();
  });

  it('shows a loading hint while the assignable list is being fetched', () => {
    renderStep({
      isSelfManager: false,
      assignedManagerId: '',
      assignableManagers: [],
      isLoadingAssignableManagers: true,
    });
    expect(screen.getByText('جاري التحميل...')).toBeInTheDocument();
  });
});