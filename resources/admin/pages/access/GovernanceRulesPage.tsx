import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconBuildingBank } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { DepartmentSummary, GovernanceRule } from '@admin/model/admin';
import { useAuth } from '@shared/contexts/AuthContext';
import { Alert } from '@shared/ui/Alert'; import { Card } from '@shared/ui/Card'; import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';

export function GovernanceRulesPage() {
  const { t } = useTranslation(); const { user } = useAuth(); const [rows, setRows] = useState<GovernanceRule[]>([]); const [departments, setDepartments] = useState<DepartmentSummary[]>([]); const [error, setError] = useState<string | null>(null); const [loading, setLoading] = useState(true);
  const organizationId = (user as { organization_id?: number | null } | null)?.organization_id;
  useEffect(() => {
    if (typeof organizationId !== 'number') {
      setError(t('admin.governance.organizationRequired'));
      setLoading(false);
      return;
    }

    void Promise.all([adminApi.governance.list(), adminApi.departments.summary(organizationId)]).then(([rules, units]) => { setRows(rules.data); setDepartments(units.data); }).catch((caught) => setError(apiErrorMessage(caught, t('common.error')))).finally(() => setLoading(false));
  }, [organizationId, t]);
  const save = async (row: GovernanceRule, value: string) => { const governing_unit_id = value ? Number(value) : null; const previousGoverningUnitId = row.governing_unit_id; setRows((current) => current.map((item) => item.resource_type === row.resource_type ? { ...item, governing_unit_id } : item)); try { await adminApi.governance.update({ resource_type: row.resource_type, governing_unit_id }); } catch (caught) { setRows((current) => current.map((item) => item.resource_type === row.resource_type ? { ...item, governing_unit_id: previousGoverningUnitId } : item)); setError(apiErrorMessage(caught, t('common.error'))); } };
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><PageHeader icon={IconBuildingBank} iconTone="admin" title={t('admin.access.tabs.governance')} />{error && <Alert variant="danger">{error}</Alert>}<Card>{loading ? <p>{t('common.loading')}</p> : rows.map((row) => <label key={row.resource_type} className="mb-4 block text-sm">{row.label}<select aria-label={row.label} className="mt-1 block w-full rounded-lg border p-2" value={row.governing_unit_id ?? ''} onChange={(event) => void save(row, event.target.value)}><option value="">—</option>{departments.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}</select></label>)}</Card></div>;
}
