import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';

const mockEmployeesGetOne = vi.fn();
const mockEmployeesDelete = vi.fn();
const mockDepartmentsGetList = vi.fn();
const mockUsersGetAll = vi.fn();
const mockShowToast = vi.fn();

vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'common.cancel': 'إلغاء',
    'common.update': 'تحديث',
    'common.add': 'إضافة',
    'common.save': 'حفظ',
    'common.delete': 'حذف',
    'common.back': 'رجوع',
    'common.loading': 'جاري التحميل...',
    'common.error_occurred': 'حدث خطأ',
    'common.select': 'اختر',
    'common.none': 'لا يوجد',
    'common.search': 'بحث',
    'hr.status_active': 'نشط',
    'hr.status_suspended': 'موقوف',
    'hr.status_on_leave': 'في إجازة',
    'hr.status_terminated': 'منتهي',
    'hr.employees': 'الموظفون',
    'hr.employees_subtitle': 'إدارة الملفات الوظيفية',
    'hr.edit_employee': 'تعديل بيانات الموظف',
    'hr.create_employee': 'إضافة موظف',
    'hr.delete_employee': 'حذف الموظف',
    'hr.employee_not_found': 'الموظف غير موجود',
    'hr.back_to_employees': 'العودة إلى قائمة الموظفين',
    'hr.employee_deleted': 'تم حذف الموظف',
    'hr.section_basic_info': 'المعلومات الأساسية',
    'hr.section_contact_emergency': 'الاتصال والطوارئ',
    'hr.section_identity_residence': 'الهوية والإقامة',
    'hr.section_employment_profile': 'الملف الوظيفي',
    'hr.section_employment_details': 'تفاصيل التوظيف',
    'hr.section_certificates': 'الشهادات',
    'hr.field_full_name_arabic': 'الاسم الكامل (عربي)',
    'hr.field_full_name_english': 'الاسم الكامل (إنجليزي)',
    'hr.field_gender': 'الجنس',
    'hr.field_birth_date': 'تاريخ الميلاد',
    'hr.field_nationality': 'الجنسية',
    'hr.field_email': 'البريد الإلكتروني',
    'hr.email_readonly_hint': 'يُدار من حساب المستخدم',
    'hr.field_phone': 'رقم الهاتف',
    'hr.field_extension': 'التحويلة',
    'hr.field_emergency_contact': 'جهة الاتصال للطوارئ',
    'hr.field_emergency_phone': 'هاتف الطوارئ',
    'hr.field_emergency_contact_relation': 'صلة القرابة',
    'hr.field_address': 'العنوان',
    'hr.field_employee_no': 'الرقم الوظيفي',
    'hr.field_department': 'القسم',
    'hr.field_job_title': 'المسمى الوظيفي',
    'hr.field_hire_date': 'تاريخ التعيين',
    'hr.field_ministry_hire_date': 'تاريخ التعيين الرسمي',
    'hr.field_contract_type': 'نوع العقد',
    'hr.field_staff_category': 'فئة الموظفين',
    'hr.field_social_insurance_number': 'رقم التأمينات الاجتماعية',
    'hr.field_specialization': 'التخصص',
    'hr.field_current_work_field': 'مجال العمل الحالي',
    'hr.field_fingerprint_number': 'رقم البصمة',
    'hr.field_employment_type': 'نوع التوظيف',
    'hr.field_employment_status': 'الحالة الوظيفية',
    'hr.field_manager': 'المدير المباشر',
    'hr.field_notes': 'ملاحظات',
    'hr.gender_male': 'ذكر',
    'hr.gender_female': 'أنثى',
    'hr.nationality_saudi': 'سعودي',
    'hr.nationality_other': 'غير سعودي',
    'hr.staff_category_medical': 'طبي',
    'hr.staff_category_administrative': 'إداري',
    'hr.contract_type_self_employed': 'نظام التأمينات',
    'hr.contract_type_civil_service': 'نظام الخدمة المدنية',
    'hr.employment_type_full_time': 'دوام كامل',
    'hr.employment_type_part_time': 'دوام جزئي',
    'hr.employment_type_contract': 'متعاقد',
    'hr.cert_graduation': 'شهادة التخرج',
    'hr.cert_bls': 'دعم الحياة الأساسي (BLS)',
    'hr.cert_acls': 'دعم الحياة القلبي المتقدم (ACLS)',
    'hr.cert_medical_malpractice': 'التأمين ضد الأخطاء الطبية',
    'hr.cert_health_specialties': 'تخصصات صحية',
    'hr.cert_additional_qualifications': 'مؤهلات إضافية',
    'hr.cert_issued_at': 'تاريخ الإصدار',
    'hr.cert_expires_at': 'تاريخ الانتهاء',
    'hr.cert_upload': 'رفع ملف',
    'hr.cert_replace': 'استبدال الملف',
    'hr.cert_required_for_medical': 'بعض الشهادات إلزامية لفريق العمل الطبي.',
    'hr.id_doc_upload_later': 'رفع صورة الهوية سيتم لاحقاً',
    'hr.search_employees': 'اسم، بريد، رقم وظيفي',
  };
  const resolveKey = (key: string) => translations[key] ?? key;
  return {
    useTranslation: () => ({
      t: (key: string) => resolveKey(key),
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
    Trans: ({ i18nKey }: { i18nKey: string }) => resolveKey(i18nKey),
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

vi.mock('@entities/hr', () => ({
  employeesApi: {
    getOne: (id: number) => mockEmployeesGetOne(id),
    delete: (id: number) => mockEmployeesDelete(id),
  },
  departmentsApi: {
    getList: () => mockDepartmentsGetList(),
  },
}));

vi.mock('@entities/user', () => ({
  usersApi: {
    getAll: (params: unknown) => mockUsersGetAll(params),
  },
}));

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    can: (capability: string) => capability === 'hr.manage',
    // Phase 9.3: production code reads useCan('hr.manage') from `user.access`;
    // mirror the legacy predicate by exposing the canonical capability.
    user: {
      id: 1,
      access: {
        'hr.manage': true,
      },
    },
  }),
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: mockShowToast }),
}));

interface SelectProps {
  label?: string;
  value?: string;
  onChange?: (e: { target: { value: string } }) => void;
  options?: Array<{ value: string; label: string }>;
  placeholder?: string;
  disabled?: boolean;
  searchable?: boolean;
  required?: boolean;
  error?: string;
}

interface InputProps {
  label?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  type?: string;
  disabled?: boolean;
  required?: boolean;
  hint?: string;
}

interface DatePickerProps {
  label?: string;
  value?: string;
  onChange?: (value: string) => void;
}

interface TextareaProps {
  label?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLTextAreaElement>) => void;
  rows?: number;
}

vi.mock('@shared/ui', () => ({
  Button: ({ children, onClick, type, loading, disabled, variant }: {
    children: React.ReactNode;
    onClick?: () => void;
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    variant?: string;
  }) => (
    <button
      onClick={onClick}
      type={type}
      disabled={loading || disabled}
      data-variant={variant}
    >
      {children}
    </button>
  ),
  Input: ({ label, value, onChange, type, disabled, required, hint }: InputProps) => (
    <div>
      <label>{label}{required ? ' *' : ''}</label>
      <input
        value={value ?? ''}
        onChange={onChange}
        type={type ?? 'text'}
        disabled={disabled}
        required={required}
        data-testid={`input-${label}`}
        aria-label={label}
      />
      {hint && <span data-testid={`hint-${label}`}>{hint}</span>}
    </div>
  ),
  Select: ({ label, value, onChange, options, placeholder, disabled, required }: SelectProps) => (
    <div>
      <label>{label}{required ? ' *' : ''}</label>
      <select
        value={value ?? ''}
        onChange={onChange}
        disabled={disabled}
        data-testid={`select-${label}`}
        aria-label={label}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options?.map(opt => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>
    </div>
  ),
  DatePicker: ({ label, value, onChange }: DatePickerProps) => (
    <div>
      <label>{label}</label>
      <input
        type="date"
        value={value ?? ''}
        onChange={e => onChange?.(e.target.value)}
        data-testid={`datepicker-${label}`}
        aria-label={label}
      />
    </div>
  ),
  Textarea: ({ label, value, onChange, rows }: TextareaProps) => (
    <div>
      <label>{label}</label>
      <textarea
        value={value ?? ''}
        onChange={onChange}
        rows={rows}
        data-testid={`textarea-${label}`}
        aria-label={label}
      />
    </div>
  ),
  Card: ({ children }: { children: React.ReactNode }) => <div data-testid="card">{children}</div>,
  FormSection: ({ title, children }: { title: string; children: React.ReactNode }) => (
    <section data-testid={`form-section-${title}`}>
      <h2>{title}</h2>
      {children}
    </section>
  ),
  FormActions: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="form-actions">{children}</div>
  ),
  PageHeader: ({ title, subtitle, actions, back }: {
    title: string;
    subtitle?: string;
    actions?: React.ReactNode;
    back?: React.ReactNode;
  }) => (
    <header data-testid="page-header">
      {back}
      <h1>{title}</h1>
      {subtitle && <p data-testid="page-subtitle">{subtitle}</p>}
      {actions && <div data-testid="page-actions">{actions}</div>}
    </header>
  ),
  Alert: ({ children, title }: { children: React.ReactNode; title?: string }) => (
    <div role="alert" data-testid="alert">
      {title && <h3>{title}</h3>}
      {children}
    </div>
  ),
  DeleteConfirmationModal: () => null,
}));

import EmployeeEditPage from '@pages/hr/EmployeeEditPage';
import type { Employee } from '@pages/hr/components/types';

const baseEmployee: Employee = {
  id: 1,
  user_id: 10,
  name: 'سعد عبدالله',
  email: 'saad@example.com',
  phone: '0501234567',
  extension: '1234',
  job_title: 'طبيب',
  department: { id: 1, name: 'الطب الباطني' },
  manager: null,
  employee_profile: {
    id: 1,
    employee_no: 'EMP-100',
    hire_date: '2024-01-15',
    ministry_hire_date: null,
    employment_type: 'full_time',
    employment_status: 'active',
    dept_id: 1,
    staff_category: 'medical',
    contract_type: 'civil_service',
    social_insurance_number: null,
    specialization: null,
    current_work_field: null,
    fingerprint_number: null,
    manager_id: null,
    notes: null,
    personal_info: {
      full_name_english: 'Saad Abdullah',
      full_name_arabic: 'سعد عبدالله',
      nationality: 'SA',
      gender: 'male',
      birth_date: '1990-01-01',
      address: 'الرياض',
      emergency_contact: null,
      emergency_phone: null,
      emergency_contact_relation: null,
      national_id: '1234567890',
      national_id_issue_date: null,
      national_id_issue_place: null,
      national_id_expiry_date: null,
      iqama_number: null,
      iqama_issue_date: null,
      iqama_issue_place: null,
      iqama_expiry_date: null,
      profession: null,
      religion: null,
      sponsor: null,
    },
    certificates: [],
  },
};

const renderPage = (initialRoute = '/hr/employees/1/edit') =>
  render(
    <MemoryRouter initialEntries={[initialRoute]}>
      <Routes>
        <Route path="/hr/employees/:id/edit" element={<EmployeeEditPage />} />
        <Route path="/hr/employees" element={<div data-testid="employees-list" />} />
      </Routes>
    </MemoryRouter>
  );

describe('EmployeeEditPage — loading state', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUsersGetAll.mockResolvedValue({ data: [] });
    mockDepartmentsGetList.mockResolvedValue({ data: [] });
  });

  it('shows loading state while fetching the employee', () => {
    mockEmployeesGetOne.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText('جاري التحميل...')).toBeInTheDocument();
  });
});

describe('EmployeeEditPage — fetched employee', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUsersGetAll.mockResolvedValue({ data: [] });
    mockDepartmentsGetList.mockResolvedValue({ data: [] });
  });

  it('renders the PageHeader with the employee name from fetched data', async () => {
    mockEmployeesGetOne.mockResolvedValue(baseEmployee);
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId('page-header')).toBeInTheDocument();
    });
    expect(screen.getByTestId('page-header')).toHaveTextContent('تعديل بيانات الموظف');
    expect(screen.getByTestId('page-subtitle')).toHaveTextContent('سعد عبدالله');
  });

  it('renders the delete button when manage_hr permission is granted', async () => {
    mockEmployeesGetOne.mockResolvedValue(baseEmployee);
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId('page-actions')).toBeInTheDocument();
    });
    expect(screen.getByText('حذف')).toBeInTheDocument();
  });
});

describe('EmployeeEditPage — error states', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUsersGetAll.mockResolvedValue({ data: [] });
    mockDepartmentsGetList.mockResolvedValue({ data: [] });
  });

  it('shows an error state when the employee is not found (404)', async () => {
    mockEmployeesGetOne.mockRejectedValue({ response: { status: 404 } });
    renderPage();
    await waitFor(() => {
      expect(screen.getByTestId('alert')).toBeInTheDocument();
    });
    expect(screen.getByTestId('alert')).toHaveTextContent('الموظف غير موجود');
  });
});
