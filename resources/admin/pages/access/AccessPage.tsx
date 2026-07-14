import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconUsers } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type {
  AccessSummary,
  AdminUserSummary,
  OperationalRoleAssignmentInput,
  RoleDefinition,
} from '@admin/model/admin';
import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { SkeletonTable } from '@shared/ui/Skeleton';

type AdminAuthorityFlags = {
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
  organization_id?: number | null;
};

function authorityFlags(user: unknown): AdminAuthorityFlags {
  return (user ?? {}) as AdminAuthorityFlags;
}

export function isPureOrganizationSuperAdmin(user: unknown): boolean {
  const flags = authorityFlags(user);
  return flags.is_organization_super_admin === true && flags.is_super_admin !== true;
}

export function isOperationalRole(role: RoleDefinition): boolean {
  return role.is_system === false
    && role.is_admin_role === false
    && role.is_active !== false;
}

function EmptyNotice({ title, description }: { title: string; description: string }) {
  return (
    <div role="status" className="py-8 text-center">
      <p className="font-medium text-[var(--text-secondary)]">{title}</p>
      <p className="mx-auto mt-2 max-w-md text-sm text-[var(--text-tertiary)]">{description}</p>
    </div>
  );
}

export function AccessPage() {
  const { t, i18n } = useTranslation();
  const { user } = useAuth();
  const flags = authorityFlags(user);
  const organizationId = typeof flags.organization_id === 'number' ? flags.organization_id : null;
  const canAssignOperationalRoles = isPureOrganizationSuperAdmin(user);
  const canUseOrgSuperEndpoint = canAssignOperationalRoles && organizationId !== null;
  const [users, setUsers] = useState<AdminUserSummary[]>([]);
  const [roles, setRoles] = useState<RoleDefinition[]>([]);
  const [selectedUser, setSelectedUser] = useState<AdminUserSummary | null>(null);
  const [selectedRoleId, setSelectedRoleId] = useState('');
  const [summary, setSummary] = useState<AccessSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [summaryLoading, setSummaryLoading] = useState(false);
  const [assigning, setAssigning] = useState(false);

  useEffect(() => {
    let active = true;

    (async () => {
      setLoading(true);
      setError(null);
      try {
        const [usersResponse, rolesResponse] = await Promise.all([
          adminApi.users.summary(),
          canUseOrgSuperEndpoint ? adminApi.roles.list() : Promise.resolve(null),
        ]);
        if (!active) return;
        setUsers(usersResponse.data);
        setRoles((rolesResponse?.data ?? []).filter(isOperationalRole));
      } catch (caught) {
        if (active) setError(apiErrorMessage(caught, t('admin.access.loadError')));
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, [canUseOrgSuperEndpoint, t]);

  const roleLabel = (role: RoleDefinition): string => {
    if (i18n.resolvedLanguage?.startsWith('ar')) {
      return role.label_ar || role.display_name;
    }
    return role.label_en || role.display_name;
  };

  const scopeLabel = (scopeType: string, scopeName: string | null): string => {
    if (scopeType === 'organization') return t('admin.access.scopes.organization');
    return scopeName || scopeType;
  };

  const open = async (selected: AdminUserSummary) => {
    setSelectedUser(selected);
    setSelectedRoleId('');
    setSummary(null);
    setError(null);
    setSuccess(null);
    setSummaryLoading(true);
    try {
      const response = await adminApi.access.summary(selected.id);
      setSummary(response.data);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('admin.access.summaryLoadError')));
    } finally {
      setSummaryLoading(false);
    }
  };

  const assign = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedUser || !canUseOrgSuperEndpoint || !selectedRoleId) return;

    const selectedRole = roles.find((role) => role.id === Number(selectedRoleId));
    if (!selectedRole) return;

    const payload: OperationalRoleAssignmentInput = {
      user_id: selectedUser.id,
      replace_all: true,
      assignments: [{
        role_id: selectedRole.id,
        scope_type: 'organization',
        scope_id: organizationId,
        inherit_to_children: false,
      }],
    };

    setAssigning(true);
    setError(null);
    setSuccess(null);
    try {
      await adminApi.access.assignOperationalRole(payload);
      setSelectedRoleId('');
      setSuccess(t('admin.access.assignment.success', {
        role: roleLabel(selectedRole),
        user: selectedUser.name,
      }));

      const response = await adminApi.access.summary(selectedUser.id);
      setSummary(response.data);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('admin.access.assignment.error')));
    } finally {
      setAssigning(false);
    }
  };

  const platformActions = flags.is_super_admin === true ? (
    <div className="flex flex-wrap gap-3 text-sm font-medium">
      <Link className="text-[var(--accent-text)] hover:underline" to="/roles">
        {t('admin.access.tabs.roles')}
      </Link>
      <Link className="text-[var(--accent-text)] hover:underline" to="/access/governance">
        {t('admin.access.tabs.governance')}
      </Link>
    </div>
  ) : undefined;

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <PageHeader
        icon={IconUsers}
        iconTone="admin"
        title={t('admin.access.title')}
        subtitle={t('admin.access.subtitle')}
        actions={platformActions}
      />

      {error && <Alert variant="danger">{error}</Alert>}
      {success && <Alert variant="success">{success}</Alert>}

      <Card>
        <div className="mb-4">
          <h2 className="font-semibold text-[var(--text-primary)]">
            {t('admin.access.members.title')}
          </h2>
          <p className="mt-1 text-sm text-[var(--text-tertiary)]">
            {t('admin.access.members.subtitle')}
          </p>
        </div>

        {loading ? (
          <div role="status" aria-live="polite" className="space-y-3">
            <p className="text-sm text-[var(--text-secondary)]">{t('common.loading')}</p>
            <SkeletonTable rows={4} columns={4} />
          </div>
        ) : users.length === 0 ? (
          <EmptyNotice
            title={t('admin.access.members.empty')}
            description={t('admin.access.members.emptyDescription')}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[36rem] text-sm">
              <thead>
                <tr className="border-b border-[var(--border-default)] text-[var(--text-secondary)]">
                  <th className="p-3 text-start font-medium">{t('admin.access.members.name')}</th>
                  <th className="p-3 text-start font-medium">{t('admin.access.members.email')}</th>
                  <th className="p-3 text-start font-medium">{t('admin.access.members.department')}</th>
                  <th className="p-3 text-end font-medium">{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {users.map((member) => {
                  const isSelected = selectedUser?.id === member.id;
                  return (
                    <tr
                      key={member.id}
                      className={isSelected ? 'bg-[var(--accent-subtle)]' : 'border-b border-[var(--border-default)]'}
                    >
                      <td className="p-3 font-medium text-[var(--text-primary)]">{member.name}</td>
                      <td className="p-3 text-[var(--text-secondary)]">{member.email}</td>
                      <td className="p-3 text-[var(--text-secondary)]">{member.department?.name ?? '—'}</td>
                      <td className="p-3 text-end">
                        <Button
                          size="sm"
                          variant="secondary"
                          aria-pressed={isSelected}
                          onClick={() => void open(member)}
                        >
                          {t('admin.access.members.viewAccess')}
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {selectedUser && (
        <div className={`grid gap-6 ${canAssignOperationalRoles ? 'xl:grid-cols-2' : ''}`}>
          <Card>
            <h2 className="font-semibold text-[var(--text-primary)]">
              {t('admin.access.drawerTitle')}
            </h2>
            <p className="mt-1 text-sm text-[var(--text-tertiary)]">{selectedUser.name}</p>

            {summaryLoading ? (
              <p role="status" className="mt-5 text-sm text-[var(--text-secondary)]">
                {t('common.loading')}
              </p>
            ) : summary && summary.assignments.length > 0 ? (
              <ul className="mt-4 divide-y divide-[var(--border-default)]">
                {summary.assignments.map((assignment) => (
                  <li key={assignment.id} className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0">
                    <div>
                      <p className="font-medium text-[var(--text-primary)]">
                        {assignment.label || assignment.role}
                      </p>
                      <p className="mt-1 text-sm text-[var(--text-tertiary)]">
                        {scopeLabel(assignment.scope_type, assignment.scope_name)}
                      </p>
                    </div>
                    <Badge variant={assignment.source === 'auto' ? 'default' : 'accent'}>
                      {t(assignment.source === 'auto' ? 'admin.access.auto' : 'admin.access.manual')}
                    </Badge>
                  </li>
                ))}
              </ul>
            ) : summary ? (
              <EmptyNotice
                title={t('admin.access.assignments.empty')}
                description={t('admin.access.assignments.emptyDescription')}
              />
            ) : null}
          </Card>

          {canAssignOperationalRoles && (
            <Card>
              <h2 className="font-semibold text-[var(--text-primary)]">
                {t('admin.access.assignment.title')}
              </h2>
              <p className="mt-1 text-sm text-[var(--text-tertiary)]">
                {t('admin.access.assignment.subtitle', { user: selectedUser.name })}
              </p>

              {!canUseOrgSuperEndpoint ? (
                <Alert className="mt-4" variant="warning">
                  {t('admin.access.assignment.organizationRequired')}
                </Alert>
              ) : roles.length === 0 ? (
                <EmptyNotice
                  title={t('admin.access.assignment.noRoles')}
                  description={t('admin.access.assignment.noRolesDescription')}
                />
              ) : (
                <form className="mt-5 space-y-4" onSubmit={(event) => void assign(event)}>
                  <label className="block text-sm font-medium text-[var(--text-primary)]">
                    {t('admin.access.assignment.role')}
                    <select
                      className="mt-2 block min-h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] px-3 py-2 text-[var(--text-primary)] focus:border-[var(--border-focus)] focus:outline-none"
                      value={selectedRoleId}
                      onChange={(event) => setSelectedRoleId(event.target.value)}
                    >
                      <option value="">{t('admin.access.assignment.selectRole')}</option>
                      {roles.map((role) => (
                        <option key={role.id} value={role.id}>{roleLabel(role)}</option>
                      ))}
                    </select>
                  </label>
                  <p className="text-sm text-[var(--text-tertiary)]">
                    {t('admin.access.assignment.scopeNotice')}
                  </p>
                  <Button
                    type="submit"
                    loading={assigning}
                    disabled={!selectedRoleId}
                  >
                    {t('admin.access.assignment.submit')}
                  </Button>
                </form>
              )}
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
