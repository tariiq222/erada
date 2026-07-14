import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconPlus, IconUserCheck, IconUserOff, IconUsers } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUser, Organization } from '@admin/model/admin';
import {
  ActorView,
  canMutateTargetLifecycle,
  isProtectedAdminTarget,
} from '@admin/model/adminPredicates';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

type LifecycleAction = 'activate' | 'deactivate';

export function UsersPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const actor = user as ActorView | null;
  const actorIsSuperAdmin = actor?.is_super_admin === true;
  const actorOrganizationId = actor?.organization_id ?? null;

  // OrgSuper never gets to swap tenants — the list and every downstream
  // request is pinned to the actor's organization. Super admin keeps the
  // cross-tenant selector so the existing flow is preserved.
  const [organizationId, setOrganizationId] = useState<number | null>(actorOrganizationId);
  const [rows, setRows] = useState<AdminUser[]>([]);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<Record<number, string>>({});
  const [actionPending, setActionPending] = useState<Record<number, LifecycleAction>>({});
  const [actionSuccess, setActionSuccess] = useState<Record<number, LifecycleAction>>({});
  const rowRequestGeneration = useRef(0);

  const effectiveOrganizationId = actorIsSuperAdmin ? organizationId : actorOrganizationId;

  const load = useCallback(
    async (requestedOrganizationId = effectiveOrganizationId, requestedSearch = search) => {
      const generation = ++rowRequestGeneration.current;
      setLoading(true);
      setError(null);
      try {
        const response = await adminApi.users.list({
          organization_id: requestedOrganizationId,
          search: requestedSearch,
          page: 1,
          per_page: 20,
        });
        if (generation === rowRequestGeneration.current) setRows(response.data);
      } catch (caught) {
        if (generation === rowRequestGeneration.current)
          setError(apiErrorMessage(caught, t('users.load_error')));
      } finally {
        if (generation === rowRequestGeneration.current) setLoading(false);
      }
    },
    [effectiveOrganizationId, search, t],
  );

  // Super admin fetches the cross-tenant organization catalog once on mount.
  // OrgSuper never makes this call — the actor's organization id is already
  // known from the auth payload and the selector is hidden entirely.
  useEffect(() => {
    if (!actorIsSuperAdmin) {
      void load(actorOrganizationId, search);
      return;
    }
    void adminApi.organizations
      .all()
      .then((response) => setOrganizations(response.data))
      .catch((caught) => setError(apiErrorMessage(caught, t('common.error'))));
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => () => { rowRequestGeneration.current++; }, []);

  const remove = async (row: AdminUser) => {
    if (!canMutateTargetLifecycle(actor, row)) return;
    if (!window.confirm(t('users.delete_confirm', { name: row.name }))) return;
    setActionError((current) => { const next = { ...current }; delete next[row.id]; return next; });
    try {
      await adminApi.users.delete(row.id);
      setRows((current) => current.filter((candidate) => candidate.id !== row.id));
    } catch (caught) {
      setActionError((current) => ({ ...current, [row.id]: apiErrorMessage(caught, t('users.delete_error')) }));
    }
  };

  const setActive = async (row: AdminUser, isActive: boolean) => {
    if (!canMutateTargetLifecycle(actor, row)) return;
    const action: LifecycleAction = isActive ? 'activate' : 'deactivate';
    setActionError((current) => { const next = { ...current }; delete next[row.id]; return next; });
    setActionPending((current) => ({ ...current, [row.id]: action }));
    try {
      await adminApi.users.setActive(row.id, isActive);
      setRows((current) =>
        current.map((candidate) => (candidate.id === row.id ? { ...candidate, is_active: isActive } : candidate)),
      );
      setActionSuccess((current) => ({ ...current, [row.id]: action }));
    } catch (caught) {
      setActionError((current) => ({ ...current, [row.id]: apiErrorMessage(caught, t('common.error')) }));
    } finally {
      setActionPending((current) => { const next = { ...current }; delete next[row.id]; return next; });
    }
  };

  const chooseOrganization = async (next: number | null) => {
    if (!actorIsSuperAdmin) return;
    setOrganizationId(next);
    await load(next, search);
  };

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader
        icon={<IconUsers className="h-6 w-6" />}
        title={t('users.title')}
        subtitle={t('users.subtitle')}
        actions={
          <Link to="/users/new" className="inline-flex items-center gap-2">
            <IconPlus className="h-4 w-4" />
            {t('users.add_user')}
          </Link>
        }
      />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form
          className="mb-4 flex flex-wrap gap-2"
          onSubmit={(event) => { event.preventDefault(); void load(effectiveOrganizationId, search); }}
        >
          {actorIsSuperAdmin && (
            <label className="min-w-52 text-sm">
              {t('admin.organizations.title')}
              <select
                aria-label={t('admin.organizations.title')}
                className="mt-1 block w-full rounded-lg border p-2"
                value={organizationId ?? ''}
                onChange={(event) => void chooseOrganization(event.target.value ? Number(event.target.value) : null)}
              >
                <option value="">{t('common.all')}</option>
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>{organization.name}</option>
                ))}
              </select>
            </label>
          )}
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('users.search_placeholder')}
          />
          <Button type="submit" variant="secondary">{t('common.search')}</Button>
        </form>
        {loading ? (
          <p>{t('common.loading')}</p>
        ) : rows.length === 0 ? (
          <p>{t('users.no_users')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b">
                  <th className="p-2 text-start">{t('common.name')}</th>
                  <th className="p-2 text-start">{t('common.email')}</th>
                  <th className="p-2 text-start">{t('common.department')}</th>
                  <th className="p-2 text-start">{t('common.status')}</th>
                  <th className="p-2 text-end">{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const canManage = canMutateTargetLifecycle(actor, row);
                  const pendingAction = actionPending[row.id];
                  const rowError = actionError[row.id];
                  const rowSuccess = actionSuccess[row.id];
                  return (
                    <tr className="border-b" key={row.id} data-testid={`users-row-${row.id}`}>
                      <td className="p-2 font-medium">
                        {row.name}
                        {isProtectedAdminTarget(row) && (
                          <span className="ms-2 text-xs text-[var(--text-tertiary)]">
                            {t('admin.users.protectedTarget')}
                          </span>
                        )}
                      </td>
                      <td className="p-2">{row.email}</td>
                      <td className="p-2">{row.department?.name ?? '—'}</td>
                      <td className="p-2">
                        <Badge variant={row.is_active ? 'success' : 'default'}>
                          {t(row.is_active ? 'common.active' : 'common.inactive')}
                        </Badge>
                      </td>
                      <td className="p-2 text-end">
                        <div className="flex justify-end gap-2">
                          <Link to={`/users/${row.id}`}>{t('common.view')}</Link>
                          <Link to={`/users/${row.id}/edit`}>{t('common.edit')}</Link>
                          {canManage && !row.is_active && (
                            <Button
                              size="sm"
                              variant="secondary"
                              disabled={Boolean(pendingAction)}
                              onClick={() => void setActive(row, true)}
                              leftIcon={<IconUserCheck className="h-4 w-4" />}
                            >
                              {t('users.activate')}
                            </Button>
                          )}
                          {canManage && row.is_active && (
                            <Button
                              size="sm"
                              variant="secondary"
                              disabled={Boolean(pendingAction)}
                              onClick={() => void setActive(row, false)}
                              leftIcon={<IconUserOff className="h-4 w-4" />}
                            >
                              {t('users.deactivate')}
                            </Button>
                          )}
                          {canManage && (
                            <Button
                              size="sm"
                              variant="danger"
                              disabled={Boolean(pendingAction)}
                              onClick={() => void remove(row)}
                            >
                              {t('common.delete')}
                            </Button>
                          )}
                        </div>
                        {rowSuccess && !pendingAction && (
                          <p className="mt-1 text-xs text-[var(--text-tertiary)]">
                            {t(rowSuccess === 'activate' ? 'users.activate_success' : 'users.deactivate_success')}
                          </p>
                        )}
                        {rowError && (
                          <Alert variant="danger" className="mt-1 inline-flex">
                            {rowError}
                          </Alert>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
