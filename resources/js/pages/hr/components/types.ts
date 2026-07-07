export type Nationality = 'SA' | string;

export type Gender = 'male' | 'female';

export type EmploymentType = 'full_time' | 'part_time' | 'contract';

export type EmploymentStatus = 'active' | 'suspended' | 'terminated' | 'on_leave';

export type StaffCategory = 'medical' | 'administrative';

export type ContractType = 'self_employed' | 'civil_service';

export type CertificateType =
  | 'graduation'
  | 'bls'
  | 'acls'
  | 'medical_malpractice_insurance'
  | 'health_specialties'
  | 'additional_qualifications';

export interface EmployeePersonalInfo {
  id?: number;
  full_name_english: string;
  full_name_arabic: string;
  nationality: string;
  gender?: Gender | null;
  birth_date?: string | null;
  address?: string | null;
  emergency_contact?: string | null;
  emergency_phone?: string | null;
  emergency_contact_relation?: string | null;
  national_id?: string | null;
  national_id_issue_date?: string | null;
  national_id_issue_place?: string | null;
  national_id_expiry_date?: string | null;
  iqama_number?: string | null;
  iqama_issue_date?: string | null;
  iqama_issue_place?: string | null;
  iqama_expiry_date?: string | null;
  profession?: string | null;
  religion?: string | null;
  sponsor?: string | null;
}

export interface EmployeeCertificate {
  id: number;
  type: CertificateType;
  title?: string | null;
  file_path?: string | null;
  file_name?: string | null;
  issued_at?: string | null;
  expires_at?: string | null;
  notes?: string | null;
  download_url?: string | null;
  is_expired?: boolean;
  is_expiring_soon?: boolean;
}

export interface EmployeeProfile {
  id?: number;
  employee_no: string;
  hire_date: string;
  ministry_hire_date?: string | null;
  employment_type: EmploymentType;
  employment_status: EmploymentStatus;
  dept_id: number;
  staff_category?: StaffCategory | null;
  contract_type?: ContractType | null;
  social_insurance_number?: string | null;
  specialization?: string | null;
  current_work_field?: string | null;
  fingerprint_number?: string | null;
  manager_id?: number | null;
  notes?: string | null;
  personal_info?: EmployeePersonalInfo | null;
  certificates?: EmployeeCertificate[];
}

export interface Employee {
  id: number;
  user_id: number;
  name: string;
  email: string;
  phone?: string | null;
  extension?: string | null;
  job_title?: string | null;
  department?: { id: number; name: string } | null;
  manager?: { id: number; name: string } | null;
  employee_profile?: EmployeeProfile | null;
}

export interface Department {
  id: number;
  name: string;
}

export interface PaginatedResponse<T = Employee> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface EmployeeFormData {
  name: string;
  email: string;
  phone: string;
  national_id: string;
  department_id: string;
  job_title: string;
  hire_date: string;
  status: string;
}

export interface EmployeeFormPayload {
  user_id?: number;
  employee_no: string;
  hire_date: string;
  ministry_hire_date?: string | null;
  employment_type: EmploymentType;
  employment_status: EmploymentStatus;
  dept_id: number;
  staff_category?: StaffCategory | null;
  contract_type?: ContractType | null;
  social_insurance_number?: string | null;
  specialization?: string | null;
  current_work_field?: string | null;
  fingerprint_number?: string | null;
  notes?: string | null;
  personal_info: EmployeePersonalInfo;
  certificates?: Array<{
    type: CertificateType;
    title?: string | null;
    issued_at?: string | null;
    expires_at?: string | null;
    notes?: string | null;
  }>;
}
