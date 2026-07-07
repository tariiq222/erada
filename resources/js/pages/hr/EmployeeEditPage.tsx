import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  IconUserCircle,
  IconTrash,
  IconAlertCircle,
  IconArrowRight,
} from '@tabler/icons-react';
import { Card, Button, Alert } from '@shared/ui';
import { PageHeader } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { useCan } from '@shared/api/access';
import { departmentsApi, employeesApi, certificatesApi } from '@entities/hr';
import EmployeeFormBody from './components/EmployeeFormBody';
import type { Department, EmployeeFormPayload } from './components/types';
import type { CertificateUploadItem } from './components/EmployeeFormBody';
import DeleteEmployeeModal from './components/DeleteEmployeeModal';
import type { Employee } from './components/types';

const EmployeeEditPage: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const canManage = useCan('hr.manage');
  const { id } = useParams<{ id: string }>();

  const [employee, setEmployee] = useState<Employee | null>(null);
  const [departments, setDepartments] = useState<Department[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const load = useCallback(async () => {
    if (!id) return;
    setIsLoading(true);
    setLoadError(null);
    try {
      const [empRes, deptRes] = await Promise.allSettled([
        employeesApi.getOne(Number(id)),
        departmentsApi.getList(),
      ]);

      if (empRes.status === 'fulfilled') {
        setEmployee((empRes.value as Employee) ?? null);
      } else {
        const status = (empRes.reason as { response?: { status?: number } })?.response?.status;
        if (status === 404) {
          setLoadError(t('hr.employee_not_found', 'الموظف غير موجود'));
        } else {
          setLoadError(t('common.error_occurred'));
        }
        setEmployee(null);
      }

      if (deptRes.status === 'fulfilled') {
        const list = ((deptRes.value as { data?: Department[] })?.data ?? []) as Department[];
        setDepartments(list);
      } else {
        setDepartments([]);
      }
    } finally {
      setIsLoading(false);
    }
  }, [id, t]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSubmit = async (
    payload: EmployeeFormPayload,
    certificatesToUpload: CertificateUploadItem[],
    certificateIdsToDelete: number[]
  ) => {
    if (!employee) return;
    setIsSaving(true);
    try {
      await employeesApi.update(employee.id, payload);
      showToast('success', t('hr.profile_saved', 'تم حفظ الملف الوظيفي'));

      for (const certId of certificateIdsToDelete) {
        try {
          await certificatesApi.delete(certId);
        } catch (error) {
          const msg = (error as { message?: string })?.message ?? t('common.error_occurred');
          showToast('error', msg);
        }
      }

      for (const cert of certificatesToUpload) {
        const fd = new FormData();
        fd.append('type', cert.type);
        if (cert.title) fd.append('title', cert.title);
        if (cert.issued_at) fd.append('issued_at', cert.issued_at);
        if (cert.expires_at) fd.append('expires_at', cert.expires_at);
        fd.append('file', cert.file);
        try {
          await certificatesApi.upload(employee.id, fd);
        } catch (error) {
          const msg = (error as { message?: string })?.message ?? t('hr.upload_failed');
          showToast('error', msg);
        }
      }

      navigate('/hr/employees');
    } catch (error) {
      const msg = (error as { message?: string })?.message ?? t('common.error_occurred');
      showToast('error', msg);
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!employee) return;
    setIsDeleting(true);
    try {
      await employeesApi.delete(employee.id);
      showToast('success', t('hr.employee_deleted', 'تم حذف الموظف'));
      setDeleteOpen(false);
      navigate('/hr/employees');
    } catch (error) {
      const msg = (error as { message?: string })?.message ?? t('common.error_occurred');
      showToast('error', msg);
    } finally {
      setIsDeleting(false);
    }
  };

  const backButton = (
    <button
      type="button"
      onClick={() => navigate('/hr/employees')}
      className="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
    >
      <IconArrowRight className="h-4 w-4 rtl:rotate-180" />
      <span>{t('common.back', 'رجوع')}</span>
    </button>
  );

  const actions = canManage ? (
    <Button
      variant="danger"
      leftIcon={<IconTrash className="h-4 w-4" />}
      onClick={() => setDeleteOpen(true)}
    >
      {t('common.delete', 'حذف')}
    </Button>
  ) : undefined;

  if (isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t('hr.edit_employee', 'تعديل بيانات الموظف')}
          icon={IconUserCircle}
          iconTone="admin"
          back={backButton}
          actions={actions}
        />
        <Card>
          <div className="p-12 text-center text-[var(--text-tertiary)]">
            {t('common.loading', 'جاري التحميل...')}
          </div>
        </Card>
      </div>
    );
  }

  if (loadError || !employee) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t('hr.edit_employee', 'تعديل بيانات الموظف')}
          icon={IconUserCircle}
          iconTone="admin"
          back={backButton}
        />
        <Card>
          <div className="space-y-4 p-6">
            <Alert
              variant="danger"
              icon={<IconAlertCircle className="h-5 w-5" />}
              title={loadError ?? t('hr.employee_not_found', 'الموظف غير موجود')}
            />
            <Button variant="secondary" onClick={() => navigate('/hr/employees')}>
              {t('hr.back_to_employees', 'العودة إلى قائمة الموظفين')}
            </Button>
          </div>
        </Card>
      </div>
    );
  }

  const subtitle = employee.employee_profile?.personal_info?.full_name_arabic ?? employee.name;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('hr.edit_employee', 'تعديل بيانات الموظف')}
        subtitle={subtitle}
        icon={IconUserCircle}
        iconTone="admin"
        back={backButton}
        actions={actions}
      />
      <Card>
        <EmployeeFormBody
          mode="edit"
          initialEmployee={employee}
          departments={departments}
          isSaving={isSaving}
          onSubmit={handleSubmit}
          onCancel={() => navigate('/hr/employees')}
          submitLabel={t('common.update', 'تحديث')}
        />
      </Card>
      <DeleteEmployeeModal
        isOpen={deleteOpen}
        employee={employee}
        isDeleting={isDeleting}
        onClose={() => setDeleteOpen(false)}
        onConfirm={() => void handleDelete()}
      />
    </div>
  );
};

export default EmployeeEditPage;
