import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconNetwork, IconPlus } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminDepartment, Organization } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';

export function DepartmentsPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const actorOrganizationId = (user as { organization_id?: number | null } | null)?.organization_id ?? null;
  const queryOrganizationId = searchParams.get('organization_id');
  const [organizationId, setOrganizationId] = useState<number | null>(queryOrganizationId ? Number(queryOrganizationId) : actorOrganizationId);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [rows, setRows] = useState<AdminDepartment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const rowRequestGeneration = useRef(0);
  const load = useCallback(async (requestedOrganizationId = organizationId) => {
    const generation = ++rowRequestGeneration.current;
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.departments.list({ organization_id: requestedOrganizationId, page: 1, per_page: 20 });
      if (generation === rowRequestGeneration.current) setRows(response.data);
    } catch (caught) {
      if (generation === rowRequestGeneration.current) setError(apiErrorMessage(caught, t('hr.departments_load_error')));
    } finally {
      if (generation === rowRequestGeneration.current) setLoading(false);
    }
  }, [organizationId, t]);
  useEffect(() => {
    void adminApi.organizations.all()
      .then((response) => setOrganizations(response.data))
      .catch((caught) => setError(apiErrorMessage(caught, t('common.error'))));
    void load(organizationId);
  // Initial load only; organization changes explicitly reload.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  useEffect(() => () => { rowRequestGeneration.current++; }, []);
  const remove = async (row: AdminDepartment) => { if (!window.confirm(t('common.confirm_delete'))) return; try { await adminApi.departments.delete(row.id); setRows((current) => current.filter((candidate) => candidate.id !== row.id)); } catch (caught) { setError(apiErrorMessage(caught, t('hr.department_delete_error'))); } };
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconNetwork className="h-6 w-6" />} title={t('hr.departments')} subtitle={t('hr.departments_subtitle')} actions={<Link to={`/departments/new${organizationId ? `?organization_id=${organizationId}` : ''}`} className="inline-flex items-center gap-2"><IconPlus className="h-4 w-4" />{t('hr.add_department')}</Link>} />{error && <Alert variant="danger">{error}</Alert>}<Card><label className="mb-4 block text-sm">{t('admin.organizations.title')}<select aria-label={t('admin.organizations.title')} className="mt-1 block w-full rounded-lg border p-2" value={organizationId ?? ''} onChange={(event) => { const next = event.target.value ? Number(event.target.value) : null; setOrganizationId(next); setSearchParams(next ? { organization_id: String(next) } : {}); void load(next); }}><option value="">{t('common.all')}</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>{loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('hr.no_departments')}</p> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-start">{t('hr.department_name')}</th><th className="p-2 text-start">{t('hr.department_level')}</th><th className="p-2 text-start">{t('hr.parent_department')}</th><th className="p-2 text-start">{t('common.status')}</th><th className="p-2 text-end">{t('common.actions')}</th></tr></thead><tbody>{rows.map((row) => <tr className="border-b" key={row.id}><td className="p-2 font-medium">{row.name}</td><td className="p-2">{row.level_name}</td><td className="p-2">{row.parent?.name ?? '—'}</td><td className="p-2"><Badge variant={row.is_active ? 'success' : 'default'}>{t(row.is_active ? 'common.active' : 'common.inactive')}</Badge></td><td className="p-2 text-end"><div className="flex justify-end gap-2"><Link to={`/departments/${row.id}`}>{t('common.view')}</Link><Link to={`/departments/${row.id}/edit`}>{t('common.edit')}</Link><Button size="sm" variant="danger" onClick={() => void remove(row)}>{t('common.delete')}</Button></div></td></tr>)}</tbody></table></div>}</Card></div>;
}
