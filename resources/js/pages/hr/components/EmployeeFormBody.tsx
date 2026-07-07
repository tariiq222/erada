import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  IconTrash,
  IconDownload,
  IconUpload,
} from '@tabler/icons-react';
import { Input, Select, Textarea, Button, DatePicker } from '@shared/ui';
import { FormSection, FormActions } from '@shared/ui';
import type {
  Employee,
  EmployeePersonalInfo,
  EmployeeCertificate,
  EmployeeFormPayload,
  Department,
  CertificateType,
  EmploymentType,
  EmploymentStatus,
  StaffCategory,
  ContractType,
  Gender,
} from './types';
import {
  STAFF_CATEGORY_LABELS,
  CONTRACT_TYPE_LABELS,
  GENDER_LABELS,
  EMPLOYMENT_TYPE_LABELS,
  CERTIFICATE_TYPE_LABELS,
  MEDICAL_CERTIFICATE_TYPES,
  NATIONALITY_OPTIONS,
  statusLabels,
} from './constants';

export interface UserSummary {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  extension?: string | null;
  job_title?: string | null;
}

export interface CertificateUploadItem {
  type: CertificateType;
  file: File;
  title?: string;
  issued_at?: string;
  expires_at?: string;
}

export interface EmployeeFormBodyProps {
  mode: 'create' | 'edit';
  initialEmployee?: Employee | null;
  userId?: number | null;
  user?: UserSummary | null;
  departments: Department[];
  isSaving: boolean;
  onSubmit: (
    payload: EmployeeFormPayload,
    certificatesToUpload: CertificateUploadItem[],
    certificateIdsToDelete: number[]
  ) => Promise<void>;
  onCancel: () => void;
  submitLabel?: string;
}

interface CertificateRowState {
  type: CertificateType;
  existing: EmployeeCertificate | null;
  pendingFile: File | null;
  title: string;
  issued_at: string;
  expires_at: string;
  notes: string;
  markedForDelete: boolean;
}

const emptyPersonalInfo = (): EmployeePersonalInfo => ({
  full_name_english: '',
  full_name_arabic: '',
  nationality: 'SA',
  gender: 'male',
  birth_date: null,
  address: null,
  emergency_contact: null,
  emergency_phone: null,
  emergency_contact_relation: null,
  national_id: null,
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
});

const ALL_CERTIFICATE_TYPES: CertificateType[] = [
  'graduation',
  'bls',
  'acls',
  'medical_malpractice_insurance',
  'health_specialties',
  'additional_qualifications',
];

const buildCertificateRows = (
  existing: EmployeeCertificate[] = []
): Record<CertificateType, CertificateRowState> => {
  const map: Record<string, CertificateRowState> = {};
  for (const t of ALL_CERTIFICATE_TYPES) {
    const found = existing.find(c => c.type === t) ?? null;
    map[t] = {
      type: t,
      existing: found,
      pendingFile: null,
      title: found?.title ?? '',
      issued_at: found?.issued_at ?? '',
      expires_at: found?.expires_at ?? '',
      notes: '',
      markedForDelete: false,
    };
  }
  return map as Record<CertificateType, CertificateRowState>;
};

export const EmployeeFormBody: React.FC<EmployeeFormBodyProps> = ({
  mode,
  initialEmployee,
  userId,
  user,
  departments,
  isSaving,
  onSubmit,
  onCancel,
  submitLabel,
}) => {
  const { t } = useTranslation();

  const [employeeNo, setEmployeeNo] = useState('');
  const [hireDate, setHireDate] = useState('');
  const [ministryHireDate, setMinistryHireDate] = useState('');
  const [deptId, setDeptId] = useState<number | ''>('');
  const [contractType, setContractType] = useState<ContractType | ''>('');
  const [staffCategory, setStaffCategory] = useState<StaffCategory | ''>('');
  const [socialInsurance, setSocialInsurance] = useState('');
  const [specialization, setSpecialization] = useState('');
  const [currentWorkField, setCurrentWorkField] = useState('');
  const [fingerprintNumber, setFingerprintNumber] = useState('');
  const [employmentType, setEmploymentType] = useState<EmploymentType>('full_time');
  const [employmentStatus, setEmploymentStatus] = useState<EmploymentStatus>('active');
  const [notes, setNotes] = useState('');

  const [personal, setPersonal] = useState<EmployeePersonalInfo>(emptyPersonalInfo);
  const [certRows, setCertRows] = useState<Record<CertificateType, CertificateRowState>>(
    () => buildCertificateRows([])
  );

  useEffect(() => {
    const profile = initialEmployee?.employee_profile ?? null;
    const pInfo = profile?.personal_info ?? null;
    setEmployeeNo(profile?.employee_no ?? '');
    setHireDate(profile?.hire_date ?? '');
    setMinistryHireDate(profile?.ministry_hire_date ?? '');
    setDeptId(profile?.dept_id ?? '');
    setContractType(profile?.contract_type ?? '');
    setStaffCategory(profile?.staff_category ?? '');
    setSocialInsurance(profile?.social_insurance_number ?? '');
    setSpecialization(profile?.specialization ?? '');
    setCurrentWorkField(profile?.current_work_field ?? '');
    setFingerprintNumber(profile?.fingerprint_number ?? '');
    setEmploymentType(profile?.employment_type ?? 'full_time');
    setEmploymentStatus(profile?.employment_status ?? 'active');
    setNotes(profile?.notes ?? '');
    setPersonal(pInfo ?? emptyPersonalInfo());
    setCertRows(buildCertificateRows(profile?.certificates ?? []));
  }, [initialEmployee]);

  const updatePersonal = <K extends keyof EmployeePersonalInfo>(
    key: K,
    value: EmployeePersonalInfo[K]
  ) => {
    setPersonal(prev => ({ ...prev, [key]: value }));
  };

  const updateCert = (type: CertificateType, patch: Partial<CertificateRowState>) => {
    setCertRows(prev => ({ ...prev, [type]: { ...prev[type], ...patch } }));
  };

  const handleFileChange = (type: CertificateType, file: File | null) => {
    updateCert(type, { pendingFile: file });
  };

  const handleDeleteCert = (type: CertificateType) => {
    const row = certRows[type];
    if (row.existing) {
      updateCert(type, { existing: null, markedForDelete: true });
    } else {
      updateCert(type, { existing: null, markedForDelete: true, pendingFile: null });
    }
  };

  const isSaudi = personal.nationality === 'SA';

  const visibleCertificateTypes: CertificateType[] = useMemo(
    () =>
      staffCategory === 'medical'
        ? ['graduation', ...MEDICAL_CERTIFICATE_TYPES, 'additional_qualifications']
        : ['graduation', 'additional_qualifications'],
    [staffCategory]
  );

  const renderCertificateRow = (type: CertificateType) => {
    const row = certRows[type];
    const isUploaded = Boolean(row.existing?.file_path);
    const labelKey = CERTIFICATE_TYPE_LABELS[type];
    return (
      <div
        key={type}
        data-testid={`cert-row-${type}`}
        className="rounded-md border border-[var(--border-default)] bg-[var(--surface-subtle)] p-3"
      >
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h4 className="text-sm font-semibold text-[var(--text-primary)]">{t(labelKey)}</h4>
          <div className="flex items-center gap-2">
            {isUploaded && row.existing?.download_url && (
              <a
                href={row.existing.download_url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-[var(--accent-text)] hover:bg-[var(--accent-subtle)]"
                aria-label={t('hr.cert_download')}
              >
                <IconDownload className="h-3.5 w-3.5" />
                <span>{t('hr.cert_download')}</span>
              </a>
            )}
            {isUploaded && (
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => handleDeleteCert(type)}
                leftIcon={<IconTrash className="h-3.5 w-3.5" />}
                aria-label={t('hr.cert_delete')}
              >
                {t('hr.cert_delete')}
              </Button>
            )}
          </div>
        </div>
        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
          <div>
            <label
              htmlFor={`file-${type}`}
              className="mb-1 block text-sm font-medium text-[var(--text-secondary)]"
            >
              {isUploaded ? t('hr.cert_replace') : t('hr.cert_upload')}
            </label>
            <div className="flex items-center gap-2">
              <label
                htmlFor={`file-${type}`}
                className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-[var(--border-default)] bg-[var(--surface-base)] px-3 py-2 text-sm text-[var(--text-primary)] hover:bg-[var(--surface-muted)]"
              >
                <IconUpload className="h-4 w-4" />
                <span>{row.pendingFile?.name ?? t('hr.cert_upload')}</span>
              </label>
              <input
                id={`file-${type}`}
                type="file"
                accept="application/pdf,image/jpeg,image/png"
                className="sr-only"
                onChange={e => handleFileChange(type, e.target.files?.[0] ?? null)}
              />
            </div>
            {row.existing?.file_name && !row.pendingFile && (
              <p className="mt-1 text-xs text-[var(--text-tertiary)]">{row.existing.file_name}</p>
            )}
          </div>
          <DatePicker
            label={t('hr.cert_issued_at')}
            value={row.issued_at}
            onChange={v => updateCert(type, { issued_at: v })}
          />
          <DatePicker
            label={t('hr.cert_expires_at')}
            value={row.expires_at}
            onChange={v => updateCert(type, { expires_at: v })}
          />
        </div>
      </div>
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const resolvedUserId = mode === 'edit' ? initialEmployee?.user_id : userId;
    if (!resolvedUserId) {
      return;
    }

    const certificateMetadata = (
      [
        'graduation',
        'bls',
        'acls',
        'medical_malpractice_insurance',
        'health_specialties',
      ] as CertificateType[]
    )
      .filter(type => {
        const row = certRows[type];
        if (row.markedForDelete) return false;
        if (row.existing) return true;
        return Boolean(row.title || row.issued_at || row.expires_at);
      })
      .map(type => ({
        type,
        title: certRows[type].title || null,
        issued_at: certRows[type].issued_at || null,
        expires_at: certRows[type].expires_at || null,
        notes: certRows[type].notes || null,
      }));

    const payload: EmployeeFormPayload = {
      user_id: resolvedUserId,
      employee_no: employeeNo,
      hire_date: hireDate,
      ministry_hire_date: ministryHireDate || null,
      employment_type: employmentType,
      employment_status: employmentStatus,
      dept_id: Number(deptId),
      staff_category: staffCategory || null,
      contract_type: contractType || null,
      social_insurance_number:
        contractType === 'self_employed' ? socialInsurance || null : null,
      specialization: specialization || null,
      current_work_field: currentWorkField || null,
      fingerprint_number: fingerprintNumber || null,
      notes: notes || null,
      personal_info: personal,
      certificates: certificateMetadata,
    };

    const certificatesToUpload: CertificateUploadItem[] = ALL_CERTIFICATE_TYPES.flatMap(
      type => {
        const row = certRows[type];
        if (!row.pendingFile) return [];
        return [
          {
            type,
            file: row.pendingFile,
            title: row.title || undefined,
            issued_at: row.issued_at || undefined,
            expires_at: row.expires_at || undefined,
          },
        ];
      }
    );

    const certificateIdsToDelete: number[] = ALL_CERTIFICATE_TYPES.flatMap(type => {
      const row = certRows[type];
      if (row.markedForDelete && row.existing) return [row.existing.id];
      return [];
    });

    await onSubmit(payload, certificatesToUpload, certificateIdsToDelete);
  };

  const departmentOptions = [
    { value: '', label: t('common.select', 'اختر') },
    ...departments.map(d => ({ value: String(d.id), label: d.name })),
  ];

  const nationalityOptions = NATIONALITY_OPTIONS.map(n => ({
    value: n.value,
    label: n.label ?? n.value,
  }));

  const genderOptions = (Object.keys(GENDER_LABELS) as Gender[]).map(g => ({
    value: g,
    label: t(GENDER_LABELS[g]),
  }));

  const staffCategoryOptions = (Object.keys(STAFF_CATEGORY_LABELS) as StaffCategory[]).map(s => ({
    value: s,
    label: t(STAFF_CATEGORY_LABELS[s]),
  }));

  const contractTypeOptions = (Object.keys(CONTRACT_TYPE_LABELS) as ContractType[]).map(c => ({
    value: c,
    label: t(CONTRACT_TYPE_LABELS[c]),
  }));

  const employmentTypeOptions = (Object.keys(EMPLOYMENT_TYPE_LABELS) as EmploymentType[]).map(
    et => ({ value: et, label: t(EMPLOYMENT_TYPE_LABELS[et]) })
  );

  const employmentStatusOptions = (Object.keys(statusLabels) as EmploymentStatus[]).map(es => ({
    value: es,
    label: t(statusLabels[es]),
  }));

  const resolvedEmployee = initialEmployee;
  const resolvedPhone = resolvedEmployee?.phone ?? user?.phone ?? '';
  const resolvedExtension = resolvedEmployee?.extension ?? user?.extension ?? '';
  const resolvedEmail = resolvedEmployee?.email ?? user?.email ?? '';
  const resolvedJobTitle = resolvedEmployee?.job_title ?? user?.job_title ?? '';

  return (
    <form onSubmit={handleSubmit} className="space-y-8">
      <FormSection
        title={t('hr.section_basic_info', 'المعلومات الأساسية')}
        description={t('hr.section_basic_info_desc', 'الاسم والجنسية ومعلومات الميلاد الأساسية.')}
        columns={4}
      >
        <Input
          label={t('hr.field_full_name_arabic', 'الاسم الكامل (عربي)')}
          value={personal.full_name_arabic}
          onChange={e => updatePersonal('full_name_arabic', e.target.value)}
          required
        />
        <Input
          label={t('hr.field_full_name_english', 'الاسم الكامل (إنجليزي)')}
          value={personal.full_name_english}
          onChange={e => updatePersonal('full_name_english', e.target.value)}
          required
        />
        <Select
          label={t('hr.field_gender', 'الجنس')}
          value={personal.gender ?? 'male'}
          onChange={e => updatePersonal('gender', e.target.value as Gender)}
          options={genderOptions}
        />
        <DatePicker
          label={t('hr.field_birth_date', 'تاريخ الميلاد')}
          value={personal.birth_date ?? ''}
          onChange={v => updatePersonal('birth_date', v || null)}
        />
      </FormSection>

      <FormSection
        title={t('hr.section_contact_emergency', 'الاتصال والطوارئ')}
        description={t(
          'hr.section_contact_emergency_desc',
          'معلومات الاتصال الأساسية وبيانات الطوارئ.'
        )}
        columns={3}
      >
        <Input
          label={t('hr.field_phone', 'رقم الهاتف')}
          value={resolvedPhone}
          onChange={() => undefined}
          disabled
          hint={t('hr.email_readonly_hint', 'يُدار من حساب المستخدم')}
        />
        <Input
          label={t('hr.field_extension', 'التحويلة')}
          value={resolvedExtension}
          onChange={() => undefined}
          disabled
        />
        <Input
          label={t('hr.field_email', 'البريد الإلكتروني')}
          type="email"
          value={resolvedEmail}
          onChange={() => undefined}
          disabled
        />
        <Input
          label={t('hr.field_emergency_contact', 'جهة الاتصال للطوارئ')}
          value={personal.emergency_contact ?? ''}
          onChange={e => updatePersonal('emergency_contact', e.target.value)}
        />
        <Input
          label={t('hr.field_emergency_phone', 'هاتف الطوارئ')}
          value={personal.emergency_phone ?? ''}
          onChange={e => updatePersonal('emergency_phone', e.target.value)}
        />
        <Input
          label={t('hr.field_emergency_contact_relation', 'صلة القرابة')}
          value={personal.emergency_contact_relation ?? ''}
          onChange={e => updatePersonal('emergency_contact_relation', e.target.value)}
        />
        <div className="sm:col-span-2 lg:col-span-full">
          <Textarea
            label={t('hr.field_address', 'العنوان')}
            value={personal.address ?? ''}
            onChange={e => updatePersonal('address', e.target.value)}
            rows={2}
          />
        </div>
      </FormSection>

      <FormSection
        title={t('hr.section_identity_residence', 'الهوية والإقامة')}
        description={t(
          'hr.section_identity_residence_desc',
          'مستند الهوية (للسعوديين) أو الإقامة (للمقيمين).'
        )}
        columns={4}
      >
        <Select
          label={t('hr.field_nationality', 'الجنسية')}
          value={personal.nationality}
          onChange={e => updatePersonal('nationality', e.target.value)}
          options={nationalityOptions}
          required
          searchable
        />
        {isSaudi ? (
          <>
            <Input
              label={t('hr.field_national_id', 'رقم الهوية')}
              value={personal.national_id ?? ''}
              onChange={e => updatePersonal('national_id', e.target.value)}
              hint={t('hr.national_id_hint', '10 أرقام')}
            />
            <DatePicker
              label={t('hr.field_national_id_issue_date', 'تاريخ إصدار الهوية')}
              value={personal.national_id_issue_date ?? ''}
              onChange={v => updatePersonal('national_id_issue_date', v || null)}
            />
            <Input
              label={t('hr.field_national_id_issue_place', 'مكان إصدار الهوية')}
              value={personal.national_id_issue_place ?? ''}
              onChange={e => updatePersonal('national_id_issue_place', e.target.value)}
            />
            <DatePicker
              label={t('hr.field_national_id_expiry_date', 'تاريخ انتهاء الهوية')}
              value={personal.national_id_expiry_date ?? ''}
              onChange={v => updatePersonal('national_id_expiry_date', v || null)}
            />
            <p className="sm:col-span-2 lg:col-span-full text-xs text-[var(--text-tertiary)]">
              {t('hr.id_doc_upload_later', 'رفع صورة الهوية سيتم لاحقاً')}
            </p>
          </>
        ) : (
          <>
            <Input
              label={t('hr.field_iqama_number', 'رقم الإقامة')}
              value={personal.iqama_number ?? ''}
              onChange={e => updatePersonal('iqama_number', e.target.value)}
              hint={t('hr.national_id_hint', '10 أرقام')}
            />
            <DatePicker
              label={t('hr.field_iqama_issue_date', 'تاريخ إصدار الإقامة')}
              value={personal.iqama_issue_date ?? ''}
              onChange={v => updatePersonal('iqama_issue_date', v || null)}
            />
            <Input
              label={t('hr.field_iqama_issue_place', 'مكان إصدار الإقامة')}
              value={personal.iqama_issue_place ?? ''}
              onChange={e => updatePersonal('iqama_issue_place', e.target.value)}
            />
            <DatePicker
              label={t('hr.field_iqama_expiry_date', 'تاريخ انتهاء الإقامة')}
              value={personal.iqama_expiry_date ?? ''}
              onChange={v => updatePersonal('iqama_expiry_date', v || null)}
            />
            <Input
              label={t('hr.field_profession', 'المهنة')}
              value={personal.profession ?? ''}
              onChange={e => updatePersonal('profession', e.target.value)}
            />
            <Input
              label={t('hr.field_religion', 'الديانة')}
              value={personal.religion ?? ''}
              onChange={e => updatePersonal('religion', e.target.value)}
            />
            <Input
              label={t('hr.field_sponsor', 'الكفيل')}
              value={personal.sponsor ?? ''}
              onChange={e => updatePersonal('sponsor', e.target.value)}
            />
          </>
        )}
      </FormSection>

      <FormSection
        title={t('hr.section_employment_profile', 'الملف الوظيفي')}
        description={t('hr.section_employment_profile_desc', 'تفاصيل التعيين والقسم والمدير.')}
        columns={5}
      >
        <Input
          label={t('hr.field_employee_no', 'الرقم الوظيفي')}
          value={employeeNo}
          onChange={e => setEmployeeNo(e.target.value)}
          required
        />
        <Select
          label={t('hr.field_department', 'القسم')}
          value={String(deptId)}
          onChange={e => setDeptId(e.target.value === '' ? '' : Number(e.target.value))}
          options={departmentOptions}
          required
        />
        <Input
          label={t('hr.field_job_title', 'المسمى الوظيفي')}
          value={resolvedJobTitle}
          onChange={() => undefined}
          disabled
          hint={t('hr.email_readonly_hint', 'يُدار من حساب المستخدم')}
        />
        <DatePicker
          label={t('hr.field_hire_date', 'تاريخ التعيين')}
          value={hireDate}
          onChange={setHireDate}
          required
        />
        <DatePicker
          label={t('hr.field_ministry_hire_date', 'تاريخ التعيين الرسمي')}
          value={ministryHireDate}
          onChange={v => setMinistryHireDate(v)}
        />
        <Select
          label={t('hr.field_contract_type', 'نوع العقد')}
          value={contractType}
          onChange={e => setContractType(e.target.value as ContractType)}
          options={[{ value: '', label: t('common.select', 'اختر') }, ...contractTypeOptions]}
        />
        <Select
          label={t('hr.field_staff_category', 'فئة الموظفين')}
          value={staffCategory}
          onChange={e => setStaffCategory(e.target.value as StaffCategory)}
          options={[{ value: '', label: t('common.select', 'اختر') }, ...staffCategoryOptions]}
          required
        />
        {contractType === 'self_employed' ? (
          <Input
            label={t('hr.field_social_insurance_number', 'رقم التأمينات الاجتماعية')}
            value={socialInsurance}
            onChange={e => setSocialInsurance(e.target.value)}
            required
          />
        ) : (
          <div />
        )}
        <Input
          label={t('hr.field_specialization', 'التخصص')}
          value={specialization}
          onChange={e => setSpecialization(e.target.value)}
        />
        <Input
          label={t('hr.field_current_work_field', 'مجال العمل الحالي')}
          value={currentWorkField}
          onChange={e => setCurrentWorkField(e.target.value)}
        />
      </FormSection>

      <FormSection
        title={t('hr.section_employment_details', 'تفاصيل التوظيف')}
        columns={4}
      >
        <Input
          label={t('hr.field_fingerprint_number', 'رقم البصمة')}
          value={fingerprintNumber}
          onChange={e => setFingerprintNumber(e.target.value)}
        />
        <Select
          label={t('hr.field_employment_type', 'نوع التوظيف')}
          value={employmentType}
          onChange={e => setEmploymentType(e.target.value as EmploymentType)}
          options={employmentTypeOptions}
        />
        <Select
          label={t('hr.field_employment_status', 'الحالة الوظيفية')}
          value={employmentStatus}
          onChange={e => setEmploymentStatus(e.target.value as EmploymentStatus)}
          options={employmentStatusOptions}
        />
        <div className="sm:col-span-2 lg:col-span-full">
          <Textarea
            label={t('hr.field_notes', 'ملاحظات')}
            value={notes}
            onChange={e => setNotes(e.target.value)}
            rows={3}
          />
        </div>
      </FormSection>

      <FormSection
        title={t('hr.section_certificates', 'الشهادات')}
        description={t(
          'hr.cert_required_for_medical',
          'بعض الشهادات إلزامية لفريق العمل الطبي.'
        )}
        columns={1}
      >
        <div className="space-y-3">
          {visibleCertificateTypes.map(renderCertificateRow)}
        </div>
      </FormSection>

      <FormActions>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSaving}>
          {t('common.cancel', 'إلغاء')}
        </Button>
        <Button type="submit" variant="primary" disabled={isSaving}>
          {isSaving ? t('common.saving', 'جاري الحفظ...') : submitLabel ?? t('common.save', 'حفظ')}
        </Button>
      </FormActions>
    </form>
  );
};

export default EmployeeFormBody;
