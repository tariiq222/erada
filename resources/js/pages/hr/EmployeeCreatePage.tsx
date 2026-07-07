import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconUserPlus, IconAlertCircle, IconUser } from '@tabler/icons-react';
import { Card, Button, Alert } from '@shared/ui';
import { PageHeader } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { departmentsApi } from '@entities/hr';
import { usersApi } from '@entities/user';
import EmployeeFormBody from './components/EmployeeFormBody';
import type { Department, EmployeeFormPayload } from './components/types';
import type { CertificateUploadItem, UserSummary } from './components/EmployeeFormBody';
import { employeesApi, certificatesApi } from '@entities/hr';

const EmployeeCreatePage: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const [searchParams] = useSearchParams();

  const userIdParam = searchParams.get('user_id');
  const userId = userIdParam ? Number(userIdParam) : null;
  const returnTo = searchParams.get('return') ?? '/hr/employees';

  const [departments, setDepartments] = useState<Department[]>([]);
  const [user, setUser] = useState<UserSummary | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setIsLoading(true);
      try {
        const deptRes: unknown = await departmentsApi.getList();
        if (!cancelled) {
          const list = ((deptRes as { data?: Department[] })?.data ?? []) as Department[];
          setDepartments(list);
        }
      } catch {
        if (!cancelled) setDepartments([]);
      }

      if (userId) {
        try {
          const userRes: unknown = await usersApi.getOne(userId);
          if (!cancelled) {
            setUser(((userRes as { data?: UserSummary })?.data ?? null) as UserSummary | null);
          }
        } catch {
          if (!cancelled) setUser(null);
        }
      }

      if (!cancelled) {
        setIsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [userId]);

  const handleSubmit = async (
    payload: EmployeeFormPayload,
    certificatesToUpload: CertificateUploadItem[]
  ) => {
    setIsSaving(true);
    try {
      const res = (await employeesApi.create(payload)) as { id: number };
      const newEmployeeId = res.id;

      for (const cert of certificatesToUpload) {
        const fd = new FormData();
        fd.append('type', cert.type);
        if (cert.title) fd.append('title', cert.title);
        if (cert.issued_at) fd.append('issued_at', cert.issued_at);
        if (cert.expires_at) fd.append('expires_at', cert.expires_at);
        fd.append('file', cert.file);
        try {
          await certificatesApi.upload(newEmployeeId, fd);
        } catch (error) {
          const msg = (error as { message?: string })?.message ?? t('hr.upload_failed');
          showToast('error', msg);
        }
      }

      showToast('success', t('hr.employee_created'));
      navigate('/hr/employees');
    } catch (error) {
      const msg = (error as { message?: string })?.message ?? t('common.error_occurred');
      showToast('error', msg);
      setLoadError(msg);
    } finally {
      setIsSaving(false);
    }
  };

  const cancelButton = (
    <Button variant="secondary" onClick={() => navigate(returnTo)}>
      {t('common.cancel', 'إلغاء')}
    </Button>
  );

  if (isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t('hr.create_employee', 'إضافة موظف')}
          icon={IconUserPlus}
          iconTone="admin"
          actions={cancelButton}
        />
        <Card>
          <div className="p-12 text-center text-[var(--text-tertiary)]">
            {t('common.loading', 'جاري التحميل...')}
          </div>
        </Card>
      </div>
    );
  }

  if (!userId) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t('hr.create_employee', 'إضافة موظف')}
          icon={IconUserPlus}
          iconTone="admin"
          actions={cancelButton}
        />
        <Card>
          <div className="space-y-4 p-6">
            <Alert
              variant="warning"
              icon={<IconAlertCircle className="h-5 w-5" />}
              title={t('hr.user_required_title', 'يجب إنشاء حساب المستخدم أولاً')}
            >
              {t(
                'hr.user_required_desc',
                'لكل موظف حساب مستخدم مرتبط. أنشئ الحساب أولاً ثم أكمل الملف الوظيفي.'
              )}
            </Alert>
            <div className="flex gap-2">
              <Button
                variant="primary"
                leftIcon={<IconUser className="h-4 w-4" />}
                onClick={() =>
                  navigate(
                    `/users/create?return=${encodeURIComponent('/hr/employees/create')}`
                  )
                }
              >
                {t('hr.create_user', 'إنشاء حساب')}
              </Button>
              <Button variant="secondary" onClick={() => navigate(returnTo)}>
                {t('common.cancel', 'إلغاء')}
              </Button>
            </div>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('hr.create_employee', 'إضافة موظف')}
        subtitle={user?.name}
        icon={IconUserPlus}
        iconTone="admin"
        actions={cancelButton}
      />
      <Card>
        <EmployeeFormBody
          mode="create"
          userId={userId}
          user={user}
          departments={departments}
          isSaving={isSaving}
          onSubmit={handleSubmit}
          onCancel={() => navigate(returnTo)}
          submitLabel={t('common.add', 'إضافة')}
        />
      </Card>
      {loadError ? (
        <Alert variant="danger" title={t('common.error_occurred')}>
          {loadError}
        </Alert>
      ) : null}
    </div>
  );
};

export default EmployeeCreatePage;
