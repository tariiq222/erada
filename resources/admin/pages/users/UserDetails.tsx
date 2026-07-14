import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconLockOpen, IconTrash, IconUser, IconUserCheck, IconUserOff } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUser, UserSecurityStatus } from '@admin/model/admin';
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

export function UserDetails() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const actor = user as ActorView | null;
  const { userId } = useParams();
  const [record, setRecord] = useState<AdminUser | null>(null);
  const [security, setSecurity] = useState<UserSecurityStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionPending, setActionPending] = useState(false);

  useEffect(() => {
    let active = true;
    void Promise.all([adminApi.users.get(Number(userId)), adminApi.users.security(Number(userId))])
      .then(([userResponse, securityResponse]) => {
        if (active) {
          setRecord(userResponse);
          setSecurity(securityResponse.security);
        }
      })
      .catch((caught) => {
        if (active) setError(apiErrorMessage(caught, t('users.load_error')));
      });
    return () => { active = false; };
  }, [t, userId]);

  const refreshSecurity = async () => {
    const response = await adminApi.users.security(Number(userId));
    setSecurity(response.security);
  };

  const unlock = async () => {
    if (!record || !canMutateTargetLifecycle(actor, record)) return;
    setActionPending(true);
    setError(null);
    try {
      await adminApi.users.unlock(Number(userId));
      setSecurity((current) => current ? { ...current, is_locked: false, failed_attempts: 0, locked_until: null } : current);
      await refreshSecurity();
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setActionPending(false);
    }
  };

  const setActive = async (isActive: boolean) => {
    if (!record || !canMutateTargetLifecycle(actor, record)) return;
    setActionPending(true);
    setError(null);
    try {
      await adminApi.users.setActive(record.id, isActive);
      setRecord({ ...record, is_active: isActive });
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setActionPending(false);
    }
  };

  const remove = async () => {
    if (!record || !canMutateTargetLifecycle(actor, record)) return;
    if (!window.confirm(t('users.delete_confirm', { name: record.name }))) return;
    setActionPending(true);
    setError(null);
    try {
      await adminApi.users.delete(record.id);
      window.history.back();
    } catch (caught) {
      setError(apiErrorMessage(caught, t('users.delete_error')));
    } finally {
      setActionPending(false);
    }
  };

  const canManage = record ? canMutateTargetLifecycle(actor, record) : false;
  const protectedTarget = record ? isProtectedAdminTarget(record) : false;

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader
        icon={<IconUser className="h-6 w-6" />}
        title={record?.name ?? t('common.loading')}
        actions={
          record && (
            <div className="flex flex-wrap items-center gap-2">
              <Link to={`/users/${record.id}/edit`}>{t('common.edit')}</Link>
              {canManage && !record.is_active && (
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={actionPending}
                  leftIcon={<IconUserCheck className="h-4 w-4" />}
                  onClick={() => void setActive(true)}
                >
                  {t('users.activate')}
                </Button>
              )}
              {canManage && record.is_active && (
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={actionPending}
                  leftIcon={<IconUserOff className="h-4 w-4" />}
                  onClick={() => void setActive(false)}
                >
                  {t('users.deactivate')}
                </Button>
              )}
              {canManage && (
                <Button
                  size="sm"
                  variant="danger"
                  disabled={actionPending}
                  leftIcon={<IconTrash className="h-4 w-4" />}
                  onClick={() => void remove()}
                >
                  {t('common.delete')}
                </Button>
              )}
            </div>
          )
        }
      />
      {error && <Alert variant="danger">{error}</Alert>}
      {protectedTarget && (
        <Alert variant="info">{t('admin.users.protectedTargetDetail')}</Alert>
      )}
      {record && (
        <Card>
          <dl className="grid gap-4 sm:grid-cols-2">
            <div><dt>{t('common.email')}</dt><dd>{record.email}</dd></div>
            <div><dt>{t('common.department')}</dt><dd>{record.department?.name ?? '—'}</dd></div>
            <div><dt>{t('users.job_title')}</dt><dd>{record.job_title ?? '—'}</dd></div>
            <div>
              <dt>{t('common.status')}</dt>
              <dd>
                <Badge variant={record.is_active ? 'success' : 'default'}>
                  {t(record.is_active ? 'common.active' : 'common.inactive')}
                </Badge>
              </dd>
            </div>
          </dl>
        </Card>
      )}
      {security && (
        <Card>
          <h2 className="mb-4 font-semibold">{t('users.security')}</h2>
          <dl className="grid gap-4 sm:grid-cols-3">
            <div><dt>{t('common.status')}</dt><dd>{t(security.is_locked ? 'common.disabled' : 'common.enabled')}</dd></div>
            <div><dt>{t('users.last_login')}</dt><dd>{security.last_login ?? '—'}</dd></div>
            <div><dt>{t('admin.users.failedAttempts')}</dt><dd>{security.failed_attempts}</dd></div>
          </dl>
          {security.is_locked && canManage && (
            <Button className="mt-4" leftIcon={<IconLockOpen className="h-4 w-4" />} disabled={actionPending} onClick={() => void unlock()}>
              {t('admin.users.unlock')}
            </Button>
          )}
          {security.is_locked && !canManage && (
            <p className="mt-4 text-sm text-[var(--text-tertiary)]">
              {t('admin.users.unlockLockedActor')}
            </p>
          )}
        </Card>
      )}
    </div>
  );
}
