import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { scopeTypesApi, ScopeType } from '@entities/admin';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Badge } from '@shared/ui/Badge';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import {IconSearch, IconTag, IconLoader} from '@tabler/icons-react';

export const ScopeTypesList: React.FC = () => {
  const { t } = useTranslation();
  const [data, setData] = useState<ScopeType[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await scopeTypesApi.list({ search });
      setData((result as any).data || []);
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        icon={IconTag}
        iconTone="admin"
        title={t('admin.scopeTypes.title')}
        subtitle={t('admin.scopeTypes.subtitle')}
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
              placeholder={t('admin.scopeTypes.searchPlaceholder')}
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

        {loading ? (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        ) : data.length === 0 ? (
          <div className="text-center py-12 text-[var(--text-secondary)]">
            {t('admin.scopeTypes.empty')}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.scopeTypes.fields.key')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.scopeTypes.fields.labelAr')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.scopeTypes.fields.labelEn')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.scopeTypes.fields.sortOrder')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.scopeTypes.fields.status')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.map((type) => (
                  <tr
                    key={type.id}
                    className="border-b border-[var(--border)] hover:bg-[var(--bg-hover)]"
                  >
                    <td className="py-3 px-2 font-mono text-xs">{type.key}</td>
                    <td className="py-3 px-2">{type.label_ar}</td>
                    <td className="py-3 px-2">{type.label_en}</td>
                    <td className="py-3 px-2 text-center">{type.sort_order}</td>
                    <td className="py-3 px-2">
                      <Badge variant={type.is_active ? 'success' : 'default'}>
                        {type.is_active
                          ? t('common.active')
                          : t('common.inactive')}
                      </Badge>
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

export default ScopeTypesList;
