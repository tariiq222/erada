import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  organizationsApi,
  Organization,
  OrganizationType,
} from '@entities/admin';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Badge } from '@shared/ui/Badge';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import {IconSearch, IconPlus, IconBuilding, IconLoader} from '@tabler/icons-react';

const TYPE_VARIANT: Record<OrganizationType, 'success' | 'info' | 'warning' | 'default' | 'danger'> = {
  cluster: 'info',
  hospital: 'success',
  center: 'warning',
  organization: 'default',
  other: 'danger',
};

const TYPE_I18N: Record<OrganizationType, string> = {
  cluster: 'admin.organizations.types.cluster',
  hospital: 'admin.organizations.types.hospital',
  center: 'admin.organizations.types.center',
  organization: 'admin.organizations.types.organization',
  other: 'admin.organizations.types.other',
};

export const OrganizationsList: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [data, setData] = useState<Organization[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await organizationsApi.list({
        search,
        page,
        per_page: 20,
      });
      setData((result as any).data || []);
      setMeta((result as any).meta || { current_page: 1, last_page: 1, total: 0 });
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page]);

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        icon={IconBuilding}
        iconTone="admin"
        title={t('admin.organizations.title')}
        subtitle={t('admin.organizations.subtitle')}
        actions={
          <Button onClick={() => navigate('/admin/organizations/new')}>
            <IconPlus className="w-4 h-4 me-2" />
            {t('admin.organizations.add')}
          </Button>
        }
      />

      {error && (
        <Alert variant="danger">{error}</Alert>
      )}

      <Card className="p-4">
        <div className="flex items-center gap-2 mb-4">
          <div className="relative flex-1">
            <IconSearch className="absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-[var(--text-secondary)]" />
            <Input
              type="text"
              placeholder={t('admin.organizations.searchPlaceholder')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && fetchData()}
              className="ps-10"
            />
          </div>
          <Button variant="secondary" onClick={fetchData}>
            {t('common.search')}
          </Button>
        </div>

        {loading && (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        )}

        {!loading && data.length === 0 && (
          <div className="text-center py-12 text-[var(--text-secondary)]">
            {t('admin.organizations.empty')}
          </div>
        )}

        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)] text-start">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.organizations.fields.name')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.organizations.fields.code')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.organizations.fields.type')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.organizations.fields.parent')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.organizations.fields.status')}
                  </th>
                  <th className="py-3 px-2 text-end font-semibold">
                    {t('common.actions')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.map((org) => (
                  <tr
                    key={org.id}
                    className="border-b border-[var(--border)] hover:bg-[var(--bg-hover)] cursor-pointer"
                    onClick={() => navigate(`/admin/organizations/${org.id}`)}
                  >
                    <td className="py-3 px-2 font-medium">{org.name}</td>
                    <td className="py-3 px-2 font-mono text-xs">{org.code}</td>
                    <td className="py-3 px-2">
                      <Badge variant={TYPE_VARIANT[org.type]}>
                        {t(TYPE_I18N[org.type])}
                      </Badge>
                    </td>
                    <td className="py-3 px-2 text-[var(--text-secondary)]">
                      {org.parent ? (
                        <span>
                          {org.parent.name}
                          <span className="text-xs opacity-70"> ({org.parent.code})</span>
                        </span>
                      ) : (
                        <span className="text-xs opacity-50">—</span>
                      )}
                    </td>
                    <td className="py-3 px-2">
                      <Badge variant={org.is_active ? 'success' : 'default'}>
                        {org.is_active
                          ? t('common.active')
                          : t('common.inactive')}
                      </Badge>
                    </td>
                    <td className="py-3 px-2 text-end">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => {
                          e.stopPropagation();
                          navigate(`/admin/organizations/${org.id}`);
                        }}
                      >
                        {t('common.view')}
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta.last_page > 1 && (
          <div className="flex items-center justify-between mt-4 pt-4 border-t border-[var(--border)]">
            <span className="text-sm text-[var(--text-secondary)]">
              {t('common.page')} {meta.current_page} / {meta.last_page} •{' '}
              {meta.total} {t('common.total')}
            </span>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="secondary"
                disabled={page === 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                {t('common.prev')}
              </Button>
              <Button
                size="sm"
                variant="secondary"
                disabled={page === meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                {t('common.next')}
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
};

export default OrganizationsList;
