import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconLockOpen, IconUser } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUser, UserSecurityStatus } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';

export function UserDetails() {
  const { t } = useTranslation();
  const { userId } = useParams();
  const [record, setRecord] = useState<AdminUser | null>(null);
  const [security, setSecurity] = useState<UserSecurityStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { let active = true; void Promise.all([adminApi.users.get(Number(userId)), adminApi.users.security(Number(userId))]).then(([userResponse, securityResponse]) => { if (active) { setRecord(userResponse); setSecurity(securityResponse.security); } }).catch((caught) => { if (active) setError(apiErrorMessage(caught, t('users.load_error'))); }); return () => { active = false; }; }, [t, userId]);
  const unlock = async () => { try { await adminApi.users.unlock(Number(userId)); setSecurity((current) => current ? { ...current, is_locked: false, failed_attempts: 0, locked_until: null } : current); } catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); } };
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconUser className="h-6 w-6" />} title={record?.name ?? t('common.loading')} actions={record && <Link to={`/users/${record.id}/edit`}>{t('common.edit')}</Link>} />{error && <Alert variant="danger">{error}</Alert>}{record && <Card><dl className="grid gap-4 sm:grid-cols-2"><div><dt>{t('common.email')}</dt><dd>{record.email}</dd></div><div><dt>{t('common.department')}</dt><dd>{record.department?.name ?? '—'}</dd></div><div><dt>{t('users.job_title')}</dt><dd>{record.job_title ?? '—'}</dd></div><div><dt>{t('common.status')}</dt><dd><Badge variant={record.is_active ? 'success' : 'default'}>{t(record.is_active ? 'common.active' : 'common.inactive')}</Badge></dd></div></dl></Card>}{security && <Card><h2 className="mb-4 font-semibold">{t('users.security')}</h2><dl className="grid gap-4 sm:grid-cols-3"><div><dt>{t('common.status')}</dt><dd>{t(security.is_locked ? 'common.disabled' : 'common.enabled')}</dd></div><div><dt>{t('users.last_login')}</dt><dd>{security.last_login ?? '—'}</dd></div><div><dt>{t('admin.users.failedAttempts')}</dt><dd>{security.failed_attempts}</dd></div></dl>{security.is_locked && <Button className="mt-4" leftIcon={<IconLockOpen className="h-4 w-4" />} onClick={() => void unlock()}>{t('admin.users.unlock')}</Button>}</Card>}</div>;
}
