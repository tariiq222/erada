import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconBuildingBank } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { DepartmentSummary, GovernanceRule } from '@admin/model/admin';
import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';
import { useAuth } from '@shared/contexts/AuthContext';
import { Alert } from '@shared/ui/Alert';
import { Card } from '@shared/ui/Card';

type IdentifiableGovernanceRule = GovernanceRule & { resource_subtype?: string | null };

function ruleIdentity(rule: IdentifiableGovernanceRule): string {
  return `${rule.resource_type}:${rule.resource_subtype ?? ''}`;
}

export function GovernanceRulesPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [rows, setRows] = useState<GovernanceRule[]>([]);
  const [departments, setDepartments] = useState<DepartmentSummary[]>([]);
  const [pendingRuleKeys, setPendingRuleKeys] = useState<Set<string>>(() => new Set());
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const organizationId = (user as { organization_id?: number | null } | null)?.organization_id;

  useEffect(() => {
    if (typeof organizationId !== 'number') {
      setError(t('admin.governance.organizationRequired'));
      setLoading(false);
      return;
    }

    void Promise.all([adminApi.governance.list(), adminApi.departments.summary(organizationId)])
      .then(([rules, units]) => {
        setRows(rules.data);
        setDepartments(units.data);
      })
      .catch((caught) => setError(apiErrorMessage(caught, t('common.error'))))
      .finally(() => setLoading(false));
  }, [organizationId, t]);

  const save = async (row: IdentifiableGovernanceRule, value: string) => {
    const identity = ruleIdentity(row);
    if (pendingRuleKeys.has(identity)) return;

    const governing_unit_id = value ? Number(value) : null;
    const previousGoverningUnitId = row.governing_unit_id;
    setError(null);
    setPendingRuleKeys((current) => new Set(current).add(identity));
    setRows((current) => current.map((item) => (
      ruleIdentity(item) === identity ? { ...item, governing_unit_id } : item
    )));

    try {
      await adminApi.governance.update({
        resource_type: row.resource_type,
        ...(row.resource_subtype !== undefined ? { resource_subtype: row.resource_subtype } : {}),
        governing_unit_id,
      });
    } catch (caught) {
      setRows((current) => current.map((item) => (
        ruleIdentity(item) === identity
          ? { ...item, governing_unit_id: previousGoverningUnitId }
          : item
      )));
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setPendingRuleKeys((current) => {
        const next = new Set(current);
        next.delete(identity);
        return next;
      });
    }
  };

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <PageHeader icon={IconBuildingBank} iconTone="admin" title={t('admin.access.tabs.governance')} />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        {loading ? <p>{t('common.loading')}</p> : rows.map((row) => {
          const identity = ruleIdentity(row);
          return (
            <label key={identity} className="mb-4 block text-sm">
              {row.label}
              <select
                aria-label={row.label}
                className="mt-1 block w-full rounded-lg border p-2"
                disabled={pendingRuleKeys.has(identity)}
                value={row.governing_unit_id ?? ''}
                onChange={(event) => void save(row, event.target.value)}
              >
                <option value="">—</option>
                {departments.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
              </select>
            </label>
          );
        })}
      </Card>
    </div>
  );
}
