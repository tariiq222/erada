import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'common.cancel': 'إلغاء',
    'common.update': 'تحديث',
    'common.add': 'إضافة',
    'common.save': 'حفظ',
    'common.saving': 'جاري الحفظ...',
    'common.error_occurred': 'حدث خطأ',
    'common.loading': 'جاري التحميل...',
    'common.search': 'بحث',
    'common.select': 'اختر',
    'common.none': 'لا يوجد',
    'common.optional': 'اختياري',
    'common.required': 'مطلوب',
    'common.delete': 'حذف',
    'common.edit': 'تعديل',
    'common.create': 'إنشاء',
    'common.app_name': 'نظام إرادة',
    'hr.status_active': 'نشط',
    'hr.status_suspended': 'موقوف',
    'hr.status_on_leave': 'في إجازة',
    'hr.status_terminated': 'منتهي',
    'hr.section_basic_info': 'المعلومات الأساسية',
    'hr.section_basic_info_desc': 'الاسم والجنسية ومعلومات الميلاد الأساسية.',
    'hr.section_contact_emergency': 'الاتصال والطوارئ',
    'hr.section_contact_emergency_desc': 'معلومات الاتصال الأساسية وبيانات الطوارئ.',
    'hr.section_identity_residence': 'الهوية والإقامة',
    'hr.section_identity_residence_desc': 'مستند الهوية (للسعوديين) أو الإقامة (للمقيمين).',
    'hr.section_employment_profile': 'الملف الوظيفي',
    'hr.section_employment_profile_desc': 'تفاصيل التعيين والقسم والمدير.',
    'hr.section_employment_details': 'تفاصيل التوظيف',
    'hr.section_certificates': 'الشهادات',
    'hr.field_full_name_arabic': 'الاسم الكامل (عربي)',
    'hr.field_full_name_english': 'الاسم الكامل (إنجليزي)',
    'hr.field_gender': 'الجنس',
    'hr.field_birth_date': 'تاريخ الميلاد',
    'hr.field_nationality': 'الجنسية',
    'hr.field_address': 'العنوان',
    'hr.field_email': 'البريد الإلكتروني',
    'hr.email_readonly_hint': 'يُدار من حساب المستخدم',
    'hr.field_phone': 'رقم الهاتف',
    'hr.field_extension': 'التحويلة',
    'hr.field_emergency_contact': 'جهة الاتصال للطوارئ',
    'hr.field_emergency_phone': 'هاتف الطوارئ',
    'hr.field_emergency_contact_relation': 'صلة القرابة',
    'hr.field_national_id': 'رقم الهوية',
    'hr.field_national_id_issue_date': 'تاريخ إصدار الهوية',
    'hr.field_national_id_issue_place': 'مكان إصدار الهوية',
    'hr.field_national_id_expiry_date': 'تاريخ انتهاء الهوية',
    'hr.field_iqama_number': 'رقم الإقامة',
    'hr.field_iqama_issue_date': 'تاريخ إصدار الإقامة',
    'hr.field_iqama_issue_place': 'مكان إصدار الإقامة',
    'hr.field_iqama_expiry_date': 'تاريخ انتهاء الإقامة',
    'hr.field_profession': 'المهنة',
    'hr.field_religion': 'الديانة',
    'hr.field_sponsor': 'الكفيل',
    'hr.national_id_hint': '10 أرقام',
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
    'hr.field_notes': 'ملاحظات',
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
    'hr.cert_delete': 'حذف',
    'hr.cert_download': 'تحميل',
    'hr.cert_required_for_medical': 'بعض الشهادات إلزامية لفريق العمل الطبي.',
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
    'hr.id_doc_upload_later': 'رفع صورة الهوية سيتم لاحقاً',
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

const mockShowToast = vi.fn();
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
  FormSection: ({ title, children }: { title: string; children: React.ReactNode }) => (
    <section data-testid={`form-section-${title}`}>
      <h2>{title}</h2>
      {children}
    </section>
  ),
  FormActions: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="form-actions">{children}</div>
  ),
}));

import EmployeeFormBody from '@pages/hr/components/EmployeeFormBody';
import type { Department, EmployeeProfile, Employee } from '@pages/hr/components/types';

const baseProfile: Partial<EmployeeProfile> = {
  id: 1,
  employee_no: 'EMP-001',
  hire_date: '2024-01-01',
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
    full_name_english: 'Ahmad Mohammed',
    full_name_arabic: 'أحمد محمد',
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
};

const baseEmployee: Employee = {
  id: 1,
  user_id: 10,
  name: 'أحمد محمد',
  email: 'ahmed@example.com',
  phone: '0501234567',
  extension: '1234',
  job_title: 'طبيب',
  department: { id: 1, name: 'الطب الباطني' },
  manager: null,
  employee_profile: baseProfile as EmployeeProfile,
};

const baseDepartments: Department[] = [
  { id: 1, name: 'الطب الباطني' },
  { id: 2, name: 'الموارد البشرية' },
];

const renderBody = (props: Partial<React.ComponentProps<typeof EmployeeFormBody>> = {}) => {
  const onSubmit = props.onSubmit ?? vi.fn();
  const onCancel = props.onCancel ?? vi.fn();
  return render(
    <EmployeeFormBody
      mode="edit"
      initialEmployee={baseEmployee}
      departments={baseDepartments}
      isSaving={false}
      onSubmit={onSubmit}
      onCancel={onCancel}
      {...props}
    />
  );
};

describe('EmployeeFormBody — 6 sections', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders 6 FormSection components with correct titles', () => {
    renderBody();
    expect(screen.getByTestId('form-section-المعلومات الأساسية')).toBeInTheDocument();
    expect(screen.getByTestId('form-section-الاتصال والطوارئ')).toBeInTheDocument();
    expect(screen.getByTestId('form-section-الهوية والإقامة')).toBeInTheDocument();
    expect(screen.getByTestId('form-section-الملف الوظيفي')).toBeInTheDocument();
    expect(screen.getByTestId('form-section-تفاصيل التوظيف')).toBeInTheDocument();
    expect(screen.getByTestId('form-section-الشهادات')).toBeInTheDocument();
  });

  it('does not render the Direct Manager (المدير المباشر) select', () => {
    renderBody();
    expect(screen.queryByTestId('select-المدير المباشر')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('المدير المباشر')).not.toBeInTheDocument();
  });

  it('renders a FormActions bar with cancel and save buttons', () => {
    renderBody({ submitLabel: 'تحديث' });
    const actions = screen.getByTestId('form-actions');
    expect(actions).toBeInTheDocument();
    expect(actions.textContent).toContain('إلغاء');
    expect(actions.textContent).toContain('تحديث');
  });
});

describe('EmployeeFormBody — nationality conditional', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows Saudi identity fields when nationality is SA', () => {
    renderBody();
    expect(screen.getByTestId('input-رقم الهوية')).toBeInTheDocument();
    expect(screen.queryByTestId('input-رقم الإقامة')).not.toBeInTheDocument();
    expect(screen.getByTestId('datepicker-تاريخ إصدار الهوية')).toBeInTheDocument();
    expect(screen.getByTestId('datepicker-تاريخ انتهاء الهوية')).toBeInTheDocument();
  });

  it('shows Iqama fields when nationality is non-SA', () => {
    renderBody({
      initialEmployee: {
        ...baseEmployee,
        employee_profile: {
          ...(baseProfile as EmployeeProfile),
          personal_info: {
            ...(baseProfile.personal_info!),
            nationality: 'EG',
            national_id: null,
            iqama_number: '2345678901',
          },
        },
      },
    });
    expect(screen.queryByTestId('input-رقم الهوية')).not.toBeInTheDocument();
    expect(screen.getByTestId('input-رقم الإقامة')).toBeInTheDocument();
    expect(screen.getByTestId('input-المهنة')).toBeInTheDocument();
    expect(screen.getByTestId('input-الديانة')).toBeInTheDocument();
    expect(screen.getByTestId('input-الكفيل')).toBeInTheDocument();
  });

  it('toggles identity section when nationality changes from SA to EG', () => {
    renderBody();
    expect(screen.getByTestId('input-رقم الهوية')).toBeInTheDocument();
    const nationalitySelect = screen.getByTestId('select-الجنسية');
    fireEvent.change(nationalitySelect, { target: { value: 'EG' } });
    expect(screen.queryByTestId('input-رقم الهوية')).not.toBeInTheDocument();
    expect(screen.getByTestId('input-رقم الإقامة')).toBeInTheDocument();
  });
});

describe('EmployeeFormBody — staff category conditional', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows 6 certificate rows when staff_category is medical', () => {
    renderBody();
    expect(screen.getByTestId('cert-row-graduation')).toBeInTheDocument();
    expect(screen.getByTestId('cert-row-bls')).toBeInTheDocument();
    expect(screen.getByTestId('cert-row-acls')).toBeInTheDocument();
    expect(screen.getByTestId('cert-row-medical_malpractice_insurance')).toBeInTheDocument();
    expect(screen.getByTestId('cert-row-health_specialties')).toBeInTheDocument();
    expect(screen.getByTestId('cert-row-additional_qualifications')).toBeInTheDocument();
  });

  it('shows only 2 certificate rows when staff_category is administrative', () => {
    renderBody({
      initialEmployee: {
        ...baseEmployee,
        employee_profile: {
          ...(baseProfile as EmployeeProfile),
          staff_category: 'administrative',
        },
      },
    });
    expect(screen.getByTestId('cert-row-graduation')).toBeInTheDocument();
    expect(screen.queryByTestId('cert-row-bls')).not.toBeInTheDocument();
    expect(screen.queryByTestId('cert-row-acls')).not.toBeInTheDocument();
    expect(screen.queryByTestId('cert-row-medical_malpractice_insurance')).not.toBeInTheDocument();
    expect(screen.queryByTestId('cert-row-health_specialties')).not.toBeInTheDocument();
    expect(screen.getByTestId('cert-row-additional_qualifications')).toBeInTheDocument();
  });
});

describe('EmployeeFormBody — contract type conditional', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows social insurance number when contract_type is self_employed', () => {
    renderBody({
      initialEmployee: {
        ...baseEmployee,
        employee_profile: {
          ...(baseProfile as EmployeeProfile),
          contract_type: 'self_employed',
        },
      },
    });
    expect(screen.getByTestId('input-رقم التأمينات الاجتماعية')).toBeInTheDocument();
  });

  it('hides social insurance number when contract_type is civil_service', () => {
    renderBody();
    expect(screen.queryByTestId('input-رقم التأمينات الاجتماعية')).not.toBeInTheDocument();
  });
});

describe('EmployeeFormBody — submit and cancel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onSubmit with the correct payload when form is submitted', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    renderBody({ onSubmit });

    fireEvent.change(screen.getByTestId('input-الرقم الوظيفي'), {
      target: { value: 'EMP-001' },
    });
    fireEvent.change(screen.getByTestId('select-القسم'), {
      target: { value: '1' },
    });
    fireEvent.change(screen.getByTestId('datepicker-تاريخ التعيين'), {
      target: { value: '2024-01-01' },
    });

    const form = document.querySelector('form') as HTMLFormElement;
    fireEvent.submit(form);

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalled();
    });

    const [payload] = onSubmit.mock.calls[0];
    expect(payload.user_id).toBe(10);
    expect(payload.employee_no).toBe('EMP-001');
    expect(payload.dept_id).toBe(1);
    expect(payload.hire_date).toBe('2024-01-01');
    expect(payload.employment_type).toBe('full_time');
    expect(payload.employment_status).toBe('active');
    expect(payload.personal_info.full_name_arabic).toBe('أحمد محمد');
  });

  it('calls onCancel when cancel button clicked', () => {
    const onCancel = vi.fn();
    renderBody({ onCancel });
    const cancelBtn = screen.getByText('إلغاء').closest('button') as HTMLButtonElement;
    fireEvent.click(cancelBtn);
    expect(onCancel).toHaveBeenCalled();
  });
});

describe('EmployeeFormBody — accessibility', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('exposes accessible labels for primary form inputs', () => {
    renderBody();
    expect(screen.getByLabelText('الاسم الكامل (عربي)')).toBeInTheDocument();
    expect(screen.getByLabelText('الاسم الكامل (إنجليزي)')).toBeInTheDocument();
    expect(screen.getByLabelText('الجنس')).toBeInTheDocument();
    expect(screen.getByLabelText('الجنسية')).toBeInTheDocument();
    expect(screen.getByLabelText('الرقم الوظيفي')).toBeInTheDocument();
    expect(screen.getByLabelText('القسم')).toBeInTheDocument();
    expect(screen.getByLabelText('تاريخ التعيين')).toBeInTheDocument();
    expect(screen.getByLabelText('نوع العقد')).toBeInTheDocument();
    expect(screen.getByLabelText('فئة الموظفين')).toBeInTheDocument();
    expect(screen.getByLabelText('ملاحظات')).toBeInTheDocument();
  });

  it('marks the Arabic full name as required', () => {
    renderBody();
    const input = screen.getByTestId('input-الاسم الكامل (عربي)');
    expect(input).toBeRequired();
  });
});
