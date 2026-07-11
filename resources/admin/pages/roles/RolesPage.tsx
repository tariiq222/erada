import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconShield } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { RoleDefinition } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';

export function RolesPage() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<RoleDefinition[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { void adminApi.roles.list().then((response) => setRows(response.data)).catch((caught) => setError(apiErrorMessage(caught, t('common.error')))).finally(() => setLoading(false)); }, [t]);

  const remove = async (role: RoleDefinition) => {
    if (!window.confirm(t('admin.roles.confirmDelete'))) return;
    try { await adminApi.roles.delete(role.id); setRows((current) => current.filter((row) => row.id !== role.id)); }
    catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); }
  };

  return <div className="space-y-6 p-6" data-testid="admin-protected-page">
    <AdminPageHeader icon={<IconShield className="h-6 w-6" />} title={t('admin.roles.title')} subtitle={t('admin.roles.subtitle')} actions={<Link to="/roles/new">{t('admin.roles.add')}</Link>} />
    {error && <Alert variant="danger">{error}</Alert>}
    <Card>{loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('admin.roles.empty')}</p> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-start">{t('admin.roles.fields.name')}</th><th className="p-2 text-start">{t('admin.roles.fields.permissionsCount')}</th><th className="p-2 text-start">{t('admin.roles.fields.status')}</th><th className="p-2 text-end">{t('common.actions')}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className="border-b"><td className="p-2 font-medium">{row.display_name}</td><td className="p-2">{row.permissions_count}</td><td className="p-2"><Badge variant={row.is_system ? 'default' : 'accent'}>{t(row.is_system ? 'admin.roles.systemRole' : 'admin.roles.customRole')}</Badge></td><td className="p-2 text-end"><div className="flex justify-end gap-2">{!row.is_system && <><Link to={`/roles/${row.id}/edit`}>{t('common.edit')}</Link><Button size="sm" variant="danger" onClick={() => void remove(row)}>{t('common.delete')}</Button></>}</div></td></tr>)}</tbody></table></div>}</Card>
  </div>;
}
