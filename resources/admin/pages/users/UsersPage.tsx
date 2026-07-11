import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconPlus, IconUsers } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUser } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

export function UsersPage() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<AdminUser[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await adminApi.users.list({ search, page: 1, per_page: 20 });
      setRows(response.data);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('users.load_error')));
    } finally {
      setLoading(false);
    }
  }, [search, t]);

  // Search is submitted explicitly.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void load(); }, []);

  const remove = async (row: AdminUser) => {
    if (!window.confirm(t('users.delete_confirm'))) return;
    try {
      await adminApi.users.delete(row.id);
      setRows((current) => current.filter((candidate) => candidate.id !== row.id));
    } catch (caught) {
      setError(apiErrorMessage(caught, t('users.delete_error')));
    }
  };

  return <div className="space-y-6 p-6" data-testid="admin-protected-page">
    <AdminPageHeader icon={<IconUsers className="h-6 w-6" />} title={t('users.title')} subtitle={t('users.subtitle')} actions={<Link to="/users/new" className="inline-flex items-center gap-2"><IconPlus className="h-4 w-4" />{t('users.add_user')}</Link>} />
    {error && <Alert variant="danger">{error}</Alert>}
    <Card>
      <form className="mb-4 flex gap-2" onSubmit={(event) => { event.preventDefault(); void load(); }}><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('users.search_placeholder')} /><Button type="submit" variant="secondary">{t('common.search')}</Button></form>
      {loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('users.no_users')}</p> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-start">{t('common.name')}</th><th className="p-2 text-start">{t('common.email')}</th><th className="p-2 text-start">{t('common.department')}</th><th className="p-2 text-start">{t('common.status')}</th><th className="p-2 text-end">{t('common.actions')}</th></tr></thead><tbody>{rows.map((row) => <tr className="border-b" key={row.id}><td className="p-2 font-medium">{row.name}</td><td className="p-2">{row.email}</td><td className="p-2">{row.department?.name ?? '—'}</td><td className="p-2"><Badge variant={row.is_active ? 'success' : 'default'}>{t(row.is_active ? 'common.active' : 'common.inactive')}</Badge></td><td className="p-2 text-end"><div className="flex justify-end gap-2"><Link to={`/users/${row.id}`}>{t('common.view')}</Link><Link to={`/users/${row.id}/edit`}>{t('common.edit')}</Link><Button size="sm" variant="danger" onClick={() => void remove(row)}>{t('common.delete')}</Button></div></td></tr>)}</tbody></table></div>}
    </Card>
  </div>;
}
