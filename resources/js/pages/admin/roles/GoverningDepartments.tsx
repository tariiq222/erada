import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { governanceRulesApi, GovernanceRuleRow } from '@entities/role';
import { departmentsApi } from '@entities/hr';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Select } from '@shared/ui/Select';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import { IconShield, IconArrowRight, IconLoader } from '@tabler/icons-react';

interface DepartmentOption {
  id: number;
  name: string;
}

/**
 * Governing departments — the unified screen for "which department oversees a
 * resource type org-wide" (ADR-UNIFIED-ROLE-ACCESS, Phase 5). Replaces the three
 * scattered per-module governing-department settings with one table.
 */
export const GoverningDepartments: React.FC<{ embedded?: boolean }> = ({ embedded }) => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [rows, setRows] = useState<GovernanceRuleRow[]>([]);
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [savingType, setSavingType] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);

  const fetchAll = async () => {
    setLoading(true);
    setError(null);
    try {
      const [rulesRes, deptRes] = await Promise.all([
        governanceRulesApi.list(),
        departmentsApi.getList() as Promise<DepartmentOption[]>,
      ]);
      setRows((rulesRes as { data: GovernanceRuleRow[] }).data || []);
      setDepartments(deptRes || []);
    } catch (err) {
      setError((err as { message?: string })?.message || t('common.error', 'حدث خطأ'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchAll();
  }, []);

  const handleChange = async (resourceType: string, value: string) => {
    setSavingType(resourceType);
    setError(null);
    setSaved(null);
    try {
      await governanceRulesApi.update({
        resource_type: resourceType,
        governing_unit_id: value === '' ? null : Number(value),
      });
      await fetchAll();
      setSaved(resourceType);
    } catch (err) {
      setError((err as { message?: string })?.message || t('common.error', 'حدث خطأ'));
    } finally {
      setSavingType(null);
    }
  };

  const deptOptions = [
    { value: '', label: t('admin.governance.noGovernor', 'بدون إدارة حاكمة') },
    ...departments.map((d) => ({ value: String(d.id), label: d.name })),
  ];

  return (
    <div className={embedded ? 'space-y-6' : 'p-6 space-y-6'}>
      <PageHeader
        icon={IconShield}
        iconTone="admin"
        title={t('admin.governance.title', 'الإدارات الحاكمة')}
        subtitle={t(
          'admin.governance.subtitle',
          'حدّد الإدارة التي تشرف على كل نوع من العناصر على مستوى المؤسسة. أعضاؤها يرون كل عناصر هذا النوع.'
        )}
        actions={
          <Button variant="ghost" onClick={() => navigate('/admin/roles')}>
            <IconArrowRight className="w-4 h-4 me-2" />
            {t('admin.governance.backToRoles', 'الأدوار')}
          </Button>
        }
      />

      {error && <Alert variant="danger">{error}</Alert>}

      <Card className="p-4">
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.governance.resourceType', 'النوع')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.governance.governingDept', 'الإدارة الحاكمة')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.governance.appliesToChildren', 'يشمل الفروع')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.resource_type} className="border-b border-[var(--border)]">
                    <td className="py-3 px-2 font-medium">{row.label}</td>
                    <td className="py-3 px-2 max-w-xs">
                      <div className="flex items-center gap-2">
                        <Select
                          options={deptOptions}
                          value={row.governing_unit_id === null ? '' : String(row.governing_unit_id)}
                          onChange={(e) => handleChange(row.resource_type, e.target.value)}
                        />
                        {savingType === row.resource_type && (
                          <IconLoader className="w-4 h-4 animate-spin text-[var(--accent-default)]" />
                        )}
                        {saved === row.resource_type && savingType === null && (
                          <span className="text-xs text-[var(--success-default,var(--accent-default))]">
                            {t('common.saved', 'تم الحفظ')}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="py-3 px-2 text-[var(--text-secondary)]">
                      {row.governing_unit_id !== null ? t('common.yes', 'نعم') : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
};

export default GoverningDepartments;
