import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconBuilding } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { Organization } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Card } from '@shared/ui/Card';

export function OrganizationDetails() {
  const { t } = useTranslation();
  const { organizationId } = useParams();
  const [organization, setOrganization] = useState<Organization | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void adminApi.organizations.get(Number(organizationId))
      .then((response) => setOrganization(response.data))
      .catch((caught) => setError(apiErrorMessage(caught, t('common.error'))));
  }, [organizationId, t]);

  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconBuilding className="h-6 w-6" />} title={organization?.name ?? t('common.loading')} actions={<div className="flex flex-wrap gap-2"><Link to={`/organizations/${organizationId}/settings`}>{t('admin.organizationSettings.navTitle')}</Link><Link to={`/organizations/${organizationId}/edit`}>{t('common.edit')}</Link></div>} />{error && <Alert variant="danger">{error}</Alert>}{organization && <Card><dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><div><dt>{t('admin.organizations.fields.code')}</dt><dd className="font-mono">{organization.code}</dd></div><div><dt>{t('admin.organizations.fields.type')}</dt><dd>{t(`admin.organizations.types.${organization.type}`)}</dd></div><div><dt>{t('admin.organizations.fields.status')}</dt><dd><Badge variant={organization.is_active ? 'success' : 'default'}>{t(organization.is_active ? 'common.active' : 'common.inactive')}</Badge></dd></div><div><dt>{t('admin.organizations.fields.childrenCount')}</dt><dd>{organization.children_count}</dd></div><div><dt>{t('admin.organizationDetails.users')}</dt><dd>{organization.users_count ?? 0}</dd></div><div><dt>{t('admin.organizationDetails.projects')}</dt><dd>{organization.projects_count ?? 0}</dd></div></dl></Card>}</div>;
}
