import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconBuilding, IconPlus } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { Organization } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';

export function OrganizationsPage() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<Organization[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.organizations.list({ search, page: 1, per_page: 20 });
      setRows(response.data);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setLoading(false);
    }
  }, [search, t]);

  // Initial fetch only; search is submitted explicitly.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void load(); }, []);

  const remove = async (organization: Organization) => {
    if (!window.confirm(t('admin.organizations.confirmDelete'))) return;
    try {
      await adminApi.organizations.delete(organization.id);
      setRows((current) => current.filter((row) => row.id !== organization.id));
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    }
  };

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader
        icon={<IconBuilding className="h-6 w-6" />}
        title={t('admin.organizations.title')}
        subtitle={t('admin.organizations.subtitle')}
        actions={<Link to="/organizations/new" className="inline-flex items-center gap-2"><IconPlus className="h-4 w-4" />{t('admin.organizations.add')}</Link>}
      />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form className="mb-4 flex gap-2" onSubmit={(event) => { event.preventDefault(); void load(); }}>
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('admin.organizations.searchPlaceholder')} />
          <Button type="submit" variant="secondary">{t('common.search')}</Button>
        </form>
        {loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('admin.organizations.empty')}</p> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b"><th className="p-2 text-start">{t('admin.organizations.fields.name')}</th><th className="p-2 text-start">{t('admin.organizations.fields.code')}</th><th className="p-2 text-start">{t('admin.organizations.fields.status')}</th><th className="p-2 text-end">{t('common.actions')}</th></tr></thead>
              <tbody>{rows.map((row) => (
                <tr key={row.id} className="border-b">
                  <td className="p-2 font-medium">{row.name}</td><td className="p-2 font-mono text-xs">{row.code}</td>
                  <td className="p-2"><Badge variant={row.is_active ? 'success' : 'default'}>{t(row.is_active ? 'common.active' : 'common.inactive')}</Badge></td>
                  <td className="p-2 text-end"><div className="flex justify-end gap-2"><Link to={`/organizations/${row.id}`}>{t('common.view')}</Link><Link to={`/organizations/${row.id}/edit`}>{t('common.edit')}</Link>{!row.children_count && <Button size="sm" variant="danger" onClick={() => void remove(row)}>{t('common.delete')}</Button>}</div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
