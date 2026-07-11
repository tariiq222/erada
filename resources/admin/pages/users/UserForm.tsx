import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconUser } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUserInput, DepartmentSummary, Organization, RoleDefinition } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

const empty: AdminUserInput = { name: '', email: '', password: '', organization_id: null, department_id: null, phone: null, extension: null, job_title: null, is_active: true, roles: [] };

export function UserForm() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { userId } = useParams();
  const navigate = useNavigate();
  const editing = userId !== undefined;
  const [form, setForm] = useState<AdminUserInput>({ ...empty, organization_id: (user as { organization_id?: number | null } | null)?.organization_id ?? null });
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [departments, setDepartments] = useState<DepartmentSummary[]>([]);
  const [roles, setRoles] = useState<RoleDefinition[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const organizationResponse = await adminApi.organizations.list({ per_page: 100 });
        const roleResponse = await adminApi.roles.list();
        let next = form;
        if (editing) {
          const record = await adminApi.users.get(Number(userId));
          next = { name: record.name, email: record.email, password: '', organization_id: record.organization_id, department_id: record.department?.id ?? record.department_id ?? null, phone: record.phone, extension: record.extension, job_title: record.job_title, is_active: record.is_active, roles: record.roles ?? [] };
          if (active) setForm(next);
        }
        const organizationId = next.organization_id;
        const departmentResponse = organizationId ? await adminApi.departments.summary(organizationId) : { data: [] };
        if (active) { setOrganizations(organizationResponse.data); setRoles(roleResponse.data.filter((role) => role.name !== 'super_admin')); setDepartments(departmentResponse.data); }
      } catch (caught) {
        if (active) setError(apiErrorMessage(caught, t('users.load_error')));
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
    // Initial contract load; organization changes are handled explicitly.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing, userId, t]);

  const chooseOrganization = async (organizationId: number | null) => {
    setForm((current) => ({ ...current, organization_id: organizationId, department_id: null }));
    try { setDepartments(organizationId ? (await adminApi.departments.summary(organizationId)).data : []); }
    catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const nextErrors: Record<string, string> = {};
    if (!form.name.trim()) nextErrors.name = t('common.required');
    if (!form.email.trim()) nextErrors.email = t('validation.email_required');
    if (!editing && !form.password) nextErrors.password = t('common.required');
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length) return;
    setError(null);
    try {
      const payload: AdminUserInput = { ...form, phone: form.phone || null, extension: form.extension || null, job_title: form.job_title || null };
      if (editing && !payload.password) delete payload.password;
      if (editing) await adminApi.users.update(Number(userId), payload); else await adminApi.users.create(payload);
      navigate('/users');
    } catch (caught) { setError(apiErrorMessage(caught, t('users.save_error'))); }
  };

  if (loading) return <p className="p-6">{t('common.loading')}</p>;
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconUser className="h-6 w-6" />} title={t(editing ? 'users.edit' : 'users.create_new')} />{error && <Alert variant="danger">{error}</Alert>}<Card><form className="grid gap-4 md:grid-cols-2" onSubmit={(event) => void submit(event)}>
    <Input aria-label={t('common.name')} label={t('common.name')} value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} error={errors.name} />
    <Input aria-label={t('common.email')} type="email" label={t('common.email')} value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} error={errors.email} />
    <Input aria-label={t('users.password')} type="password" label={t('users.password')} value={form.password ?? ''} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} error={errors.password} />
    <label className="text-sm">{t('admin.organizations.title')}<select className="mt-1 block w-full rounded-lg border p-2" value={form.organization_id ?? ''} onChange={(event) => void chooseOrganization(event.target.value ? Number(event.target.value) : null)}><option value="">{t('common.none')}</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>
    <label className="text-sm">{t('common.department')}<select aria-label={t('common.department')} className="mt-1 block w-full rounded-lg border p-2" value={form.department_id ?? ''} onChange={(event) => setForm((current) => ({ ...current, department_id: event.target.value ? Number(event.target.value) : null }))}><option value="">{t('users.select_department')}</option>{departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label>
    <Input label={t('common.phone')} value={form.phone ?? ''} onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))} />
    <Input label={t('users.extension')} value={form.extension ?? ''} onChange={(event) => setForm((current) => ({ ...current, extension: event.target.value }))} />
    <Input label={t('users.job_title')} value={form.job_title ?? ''} onChange={(event) => setForm((current) => ({ ...current, job_title: event.target.value }))} />
    <fieldset className="space-y-2 md:col-span-2"><legend className="text-sm font-medium">{t('users.roles')}</legend>{roles.map((role) => <label className="me-4 inline-flex items-center gap-2" key={role.id}><input type="checkbox" checked={form.roles.includes(role.name)} onChange={() => setForm((current) => ({ ...current, roles: current.roles.includes(role.name) ? current.roles.filter((name) => name !== role.name) : [...current.roles, role.name] }))} />{role.display_name}</label>)}</fieldset>
    <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} />{t('users.user_active')}</label>
    <div className="flex gap-2 md:col-span-2"><Button type="submit">{t(editing ? 'common.save_changes' : 'common.create')}</Button><Button type="button" variant="secondary" onClick={() => navigate('/users')}>{t('common.cancel')}</Button></div>
  </form></Card></div>;
}
