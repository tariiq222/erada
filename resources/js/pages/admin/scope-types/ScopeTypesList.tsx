import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { scopeTypesApi, type ScopeType } from '@entities/admin';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Card } from '@shared/ui/Card';
import { PageHeader } from '@shared/ui/PageHeader';
import { IconLoader, IconTag } from '@tabler/icons-react';

export const ScopeTypesList: React.FC = () => {
  const { t } = useTranslation();
  const [data, setData] = useState<ScopeType[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    scopeTypesApi.list()
      .then((result) => setData(result.data))
      .catch((err: unknown) => {
        setError(err instanceof Error ? err.message : t('common.error'));
      })
      .finally(() => setLoading(false));
  }, [t]);

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        icon={IconTag}
        iconTone="admin"
        title={t('admin.scopeTypes.title')}
        subtitle={t('admin.scopeTypes.subtitle')}
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
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.scopeTypes.fields.key')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.scopeTypes.fields.labelAr')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.scopeTypes.fields.labelEn')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.scopeTypes.fields.targetRequirement')}</th>
                </tr>
              </thead>
              <tbody>
                {data.map((type) => (
                  <tr key={type.key} className="border-b border-[var(--border)]">
                    <td className="py-3 px-2 font-mono text-xs">{type.key}</td>
                    <td className="py-3 px-2">{type.label_ar}</td>
                    <td className="py-3 px-2">{type.label_en}</td>
                    <td className="py-3 px-2">
                      <Badge variant={type.target_requirement === 'required' ? 'info' : 'default'}>
                        {t(`admin.scopeTypes.targetRequirements.${type.target_requirement}`)}
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
