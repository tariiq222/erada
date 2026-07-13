import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { rolesApi, Role } from '@entities/role';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Badge } from '@shared/ui/Badge';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import {IconShield, IconPlus, IconSearch, IconLoader, IconLock, IconBuildingBank} from '@tabler/icons-react';

export const RolesList: React.FC<{ embedded?: boolean }> = ({ embedded }) => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [data, setData] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = (await rolesApi.list()) as any;
      setData(result.data || []);
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const filtered = data.filter(
    (r) =>
      !search ||
      r.name.toLowerCase().includes(search.toLowerCase()) ||
      roleLabel(r).includes(search)
  );

  return (
    <div className={embedded ? 'space-y-6' : 'p-6 space-y-6'}>
      <PageHeader
        icon={IconShield}
        iconTone="admin"
        title={t('admin.roles.title')}
        subtitle={t('admin.roles.subtitle')}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={() => navigate('/admin/roles/governing-departments')}>
              <IconBuildingBank className="w-4 h-4 me-2" />
              {t('admin.governance.title', 'الإدارات الحاكمة')}
            </Button>
            <Button onClick={() => navigate('/admin/roles/new')}>
              <IconPlus className="w-4 h-4 me-2" />
              {t('admin.roles.add')}
            </Button>
          </div>
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
              placeholder={t('admin.roles.searchPlaceholder')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="ps-10"
            />
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        ) : filtered.length === 0 ? (
          <div className="text-center py-12 text-[var(--text-secondary)]">
            {t('admin.roles.empty')}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.roles.fields.name')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.roles.fields.permissionsCount')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.roles.fields.usersCount')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.roles.fields.status')}
                  </th>
                  <th className="py-3 px-2 text-end font-semibold">
                    {t('common.actions')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((role) => (
                  <tr
                    key={role.id}
                    className="border-b border-[var(--border)] hover:bg-[var(--bg-hover)] cursor-pointer"
                    onClick={() => navigate(`/admin/roles/${role.id}`)}
                  >
                    <td className="py-3 px-2 font-medium">
                      <div className="flex items-center gap-2">
                        {role.is_system && (
                          <IconLock className="w-3.5 h-3.5 text-[var(--text-secondary)]" />
                        )}
                        {roleLabel(role)}
                      </div>
                      <div className="text-xs text-[var(--text-secondary)] font-mono">
                        {role.name}
                      </div>
                    </td>
                    <td className="py-3 px-2 font-mono text-xs">
                      {role.capabilities.length}
                    </td>
                    <td className="py-3 px-2 font-mono text-xs">
                      {role.users_count}
                    </td>
                    <td className="py-3 px-2">
                      {!role.is_active ? (
                        <Badge variant="danger">{t('admin.roles.inactiveRole', 'غير مفعّل')}</Badge>
                      ) : role.is_system ? (
                        <Badge variant="accent">{t('admin.roles.systemRole')}</Badge>
                      ) : (
                        <Badge variant="default">{t('admin.roles.customRole')}</Badge>
                      )}
                    </td>
                    <td className="py-3 px-2 text-end">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => {
                          e.stopPropagation();
                          navigate(`/admin/roles/${role.id}`);
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
      </Card>
    </div>
  );
};

const roleLabel = (role: Role): string => role.label_ar || role.label || role.label_en || role.name;

export default RolesList;
