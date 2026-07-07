import React, { useEffect, useState } from 'react';
import { IconBuildingCommunity } from '@tabler/icons-react';
import { Button, Card, Select, Skeleton } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { ovrSettingsApi } from '@entities/incident';

interface DepartmentOption {
  id: number;
  name: string;
  code: string | null;
  level: number;
  level_name: string;
}

interface GoverningResponse {
  department_id: number | null;
  departments: DepartmentOption[];
}

const getData = <T,>(response: unknown): T | null => {
  if (!response || typeof response !== 'object') return null;
  const data = (response as { data?: unknown }).data;
  return (data ?? response) as T;
};

const getErrorMessage = (error: unknown, fallback: string): string => {
  if (error && typeof error === 'object') {
    const message = (error as { message?: unknown }).message;
    if (typeof message === 'string' && message.trim()) return message;
    const responseMessage = (error as { response?: { data?: { message?: unknown } } }).response?.data?.message;
    if (typeof responseMessage === 'string' && responseMessage.trim()) return responseMessage;
  }
  return fallback;
};

/**
 * Governing-department section — kept identical to the original
 * standalone OVR governance settings page. Extracted so it can be embedded
 * inside the broader OVR Settings page without changing behavior.
 */
const OvrGoverningDepartmentSection: React.FC = () => {
  const { showToast } = useToast();
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [departmentId, setDepartmentId] = useState<string>('');
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const fetchSettings = async () => {
    setIsLoading(true);
    try {
      const response = getData<GoverningResponse>(await ovrSettingsApi.getGoverningDepartment());
      setDepartments(response?.departments ?? []);
      setDepartmentId(response?.department_id ? String(response.department_id) : '');
    } catch (error) {
      showToast('error', getErrorMessage(error, 'فشل تحميل إعداد القسم المُشرِف'));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchSettings();
  }, []);

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await ovrSettingsApi.updateGoverningDepartment(departmentId ? Number(departmentId) : null);
      showToast('success', 'تم حفظ القسم المُشرِف بنجاح');
      await fetchSettings();
    } catch (error) {
      showToast('error', getErrorMessage(error, 'فشل حفظ الإعداد'));
    } finally {
      setIsSaving(false);
    }
  };

  const departmentOptions = [
    { value: '', label: 'بدون قسم مُشرِف (قسم المُبلِّغ فقط)' },
    ...departments.map((dept) => ({
      value: String(dept.id),
      label: `${dept.name} (${dept.level_name})`,
    })),
  ];

  return (
    <Card className="border border-[var(--border-default)] p-0 overflow-hidden">
      <div className="flex items-center gap-2 border-b border-[var(--border-default)] px-6 py-4">
        <IconBuildingCommunity className="h-5 w-5 text-[var(--accent-default)]" />
        <div>
          <h2 className="text-base font-semibold text-[var(--text-primary)]">القسم المُشرِف</h2>
          <p className="text-sm text-[var(--text-secondary)]">
            يبقى مدير/موظف القسم قادراً على تسجيل البلاغات داخل قسمه بغضّ النظر عن هذا الإعداد.
          </p>
        </div>
      </div>

      <div className="space-y-5 p-6">
        {isLoading ? (
          <Skeleton className="h-12 w-full" />
        ) : (
          <>
            <Select
              label="القسم المُشرِف على البلاغات"
              value={departmentId}
              onChange={(e) => setDepartmentId(e.target.value)}
              options={departmentOptions}
              disabled={departments.length === 0}
            />

            <div className="flex justify-end border-t border-[var(--border-default)] pt-4">
              <Button type="button" onClick={handleSave} loading={isSaving} disabled={isLoading}>
                حفظ التغييرات
              </Button>
            </div>
          </>
        )}
      </div>
    </Card>
  );
};

export default OvrGoverningDepartmentSection;