import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconNetwork } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminDepartment } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Card } from '@shared/ui/Card';

export function DepartmentDetails() { const { t } = useTranslation(); const { departmentId } = useParams(); const [record, setRecord] = useState<AdminDepartment | null>(null); const [error, setError] = useState<string | null>(null); useEffect(() => { let active = true; void adminApi.departments.get(Number(departmentId)).then((response) => { if (active) setRecord(response); }).catch((caught) => { if (active) setError(apiErrorMessage(caught, t('hr.departments_load_error'))); }); return () => { active = false; }; }, [departmentId, t]); return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconNetwork className="h-6 w-6" />} title={record?.name ?? t('common.loading')} actions={record && <Link to={`/departments/${record.id}/edit`}>{t('common.edit')}</Link>} />{error && <Alert variant="danger">{error}</Alert>}{record && <><Card><dl className="grid gap-4 sm:grid-cols-2"><div><dt>{t('hr.department_code')}</dt><dd>{record.code ?? '—'}</dd></div><div><dt>{t('hr.department_level')}</dt><dd>{record.level_name}</dd></div><div><dt>{t('hr.parent_department')}</dt><dd>{record.parent?.name ?? '—'}</dd></div><div><dt>{t('common.status')}</dt><dd><Badge variant={record.is_active ? 'success' : 'default'}>{t(record.is_active ? 'common.active' : 'common.inactive')}</Badge></dd></div></dl></Card><Card><h2 className="mb-3 font-semibold">{t('hr.sub_departments')}</h2>{record.children?.length ? <ul className="space-y-2">{record.children.map((child) => <li key={child.id}><Link to={`/departments/${child.id}`}>{child.name}</Link></li>)}</ul> : <p>{t('hr.no_sub_departments')}</p>}</Card></>}</div>; }
