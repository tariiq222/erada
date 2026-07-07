import type {
  StaffCategory,
  ContractType,
  Gender,
  EmploymentType,
  CertificateType,
} from './types';

export const statusLabels: Record<string, string> = {
  active: 'hr.status_active',
  suspended: 'hr.status_suspended',
  on_leave: 'hr.status_on_leave',
  terminated: 'hr.status_terminated',
};

export const statusColors: Record<string, 'success' | 'warning' | 'danger'> = {
  active: 'success',
  suspended: 'warning',
  on_leave: 'warning',
  terminated: 'danger',
};

export const STAFF_CATEGORY_LABELS: Record<StaffCategory, string> = {
  medical: 'hr.staff_category_medical',
  administrative: 'hr.staff_category_administrative',
};

export const CONTRACT_TYPE_LABELS: Record<ContractType, string> = {
  self_employed: 'hr.contract_type_self_employed',
  civil_service: 'hr.contract_type_civil_service',
};

export const GENDER_LABELS: Record<Gender, string> = {
  male: 'hr.gender_male',
  female: 'hr.gender_female',
};

export const EMPLOYMENT_TYPE_LABELS: Record<EmploymentType, string> = {
  full_time: 'hr.employment_type_full_time',
  part_time: 'hr.employment_type_part_time',
  contract: 'hr.employment_type_contract',
};

export const CERTIFICATE_TYPE_LABELS: Record<CertificateType, string> = {
  graduation: 'hr.cert_graduation',
  bls: 'hr.cert_bls',
  acls: 'hr.cert_acls',
  medical_malpractice_insurance: 'hr.cert_medical_malpractice',
  health_specialties: 'hr.cert_health_specialties',
  additional_qualifications: 'hr.cert_additional_qualifications',
};

export const MEDICAL_CERTIFICATE_TYPES: CertificateType[] = [
  'bls',
  'acls',
  'medical_malpractice_insurance',
  'health_specialties',
];

export const NATIONALITY_OPTIONS: ReadonlyArray<{ value: string; labelKey: string; label?: string }> = [
  { value: 'SA', labelKey: 'hr.nationality_saudi', label: 'السعودية' },
  { value: 'EG', labelKey: 'hr.nationality_other', label: 'مصر' },
  { value: 'JO', labelKey: 'hr.nationality_other', label: 'الأردن' },
  { value: 'IN', labelKey: 'hr.nationality_other', label: 'الهند' },
  { value: 'PH', labelKey: 'hr.nationality_other', label: 'الفلبين' },
  { value: 'PK', labelKey: 'hr.nationality_other', label: 'باكستان' },
  { value: 'BD', labelKey: 'hr.nationality_other', label: 'بنغلاديش' },
  { value: 'YE', labelKey: 'hr.nationality_other', label: 'اليمن' },
  { value: 'SD', labelKey: 'hr.nationality_other', label: 'السودان' },
  { value: 'SY', labelKey: 'hr.nationality_other', label: 'سوريا' },
];
