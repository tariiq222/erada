import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconTag } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { ScopeType } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert'; import { Badge } from '@shared/ui/Badge'; import { Button } from '@shared/ui/Button'; import { Card } from '@shared/ui/Card'; import { Input } from '@shared/ui/Input'; import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';

export function ScopeTypesPage() {
  const { t } = useTranslation(); const [rows, setRows] = useState<ScopeType[]>([]); const [search, setSearch] = useState(''); const [error, setError] = useState<string | null>(null); const [loading, setLoading] = useState(true);
  const load = async () => { setLoading(true); try { const response = await adminApi.scopeTypes.list({ search }); setRows(response.data); } catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); } finally { setLoading(false); } };
  // Initial fetch only; search is submitted explicitly.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void load(); }, []);
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><PageHeader icon={IconTag} iconTone="admin" title={t('admin.scopeTypes.title')} subtitle={t('admin.scopeTypes.subtitle')} />{error && <Alert variant="danger">{error}</Alert>}<Card><div className="mb-4 flex gap-2"><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('admin.scopeTypes.searchPlaceholder')} /><Button variant="secondary" onClick={() => void load()}>{t('common.search')}</Button></div>{loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('admin.scopeTypes.empty')}</p> : <table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-start">{t('admin.scopeTypes.fields.key')}</th><th className="p-2 text-start">{t('admin.scopeTypes.fields.labelAr')}</th><th className="p-2 text-start">{t('admin.scopeTypes.fields.labelEn')}</th><th className="p-2 text-start">{t('admin.scopeTypes.fields.status')}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className="border-b"><td className="p-2 font-mono">{row.key}</td><td className="p-2">{row.label_ar}</td><td className="p-2">{row.label_en}</td><td className="p-2"><Badge variant={row.is_active ? 'success' : 'default'}>{t(row.is_active ? 'common.active' : 'common.inactive')}</Badge></td></tr>)}</tbody></table>}</Card></div>;
}
