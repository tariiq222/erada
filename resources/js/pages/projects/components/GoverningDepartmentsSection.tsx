import React, { useEffect, useState } from 'react';
import { IconBuildingCommunity } from '@tabler/icons-react';
import { Button, Card, Select, Skeleton } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { projectsApi } from '@entities/project';

interface GovernableType {
  key: string;
  label: string;
  department_id: number | null;
}

interface DepartmentOption {
  id: number;
  name: string;
  code: string | null;
  level: number;
  level_name: string;
}

interface GoverningResponse {
  types: GovernableType[];
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
 * Governing-departments section — kept identical to the original
 * /projects/governance-settings page. Extracted so it can be embedded
 * inside the broader Project Settings page without changing behavior.
 */
const GoverningDepartmentsSection: React.FC = () => {
  const { showToast } = useToast();
  const [types, setTypes] = useState<GovernableType[]>([]);
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const fetchSettings = async () => {
    setIsLoading(true);
    try {
      const response = getData<GoverningResponse>(await projectsApi.getGoverningDepartments());
      const loadedTypes = response?.types ?? [];
      setTypes(loadedTypes);
      setDepartments(response?.departments ?? []);
      setMapping(
        Object.fromEntries(
          loadedTypes.map((t) => [t.key, t.department_id ? String(t.department_id) : '']),
        ),
      );
    } catch (error) {
      showToast('error', getErrorMessage(error, 'فشل تحميل إعدادات الأقسام المُشرِفة'));
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
      const payload: Record<string, number | null> = {};
      for (const t of types) {
        const value = mapping[t.key];
        payload[t.key] = value ? Number(value) : null;
      }
      await projectsApi.updateGoverningDepartments(payload);
      showToast('success', 'تم حفظ الأقسام المُشرِفة بنجاح');
      await fetchSettings();
    } catch (error) {
      showToast('error', getErrorMessage(error, 'فشل حفظ الإعدادات'));
    } finally {
      setIsSaving(false);
    }
  };

  const departmentOptions = [
    { value: '', label: 'بدون قسم مُشرِف (القسم صاحب المشروع فقط)' },
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
          <h2 className="text-base font-semibold text-[var(--text-primary)]">الربط حسب نوع المشروع</h2>
          <p className="text-sm text-[var(--text-secondary)]">
            يبقى مدير/موظف القسم قادراً على الإنشاء داخل قسمه بغضّ النظر عن هذا الإعداد.
          </p>
        </div>
      </div>

      <div className="space-y-4 p-4 sm:p-6">
        {isLoading ? (
          <div className="space-y-4">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : (
          <>
            {types.map((t) => (
              <Select
                key={t.key}
                label={t.label}
                value={mapping[t.key] ?? ''}
                onChange={(e) => setMapping((prev) => ({ ...prev, [t.key]: e.target.value }))}
                options={departmentOptions}
                disabled={departments.length === 0}
              />
            ))}

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

export default GoverningDepartmentsSection;
