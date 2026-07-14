import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconUser } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminUserInput, DepartmentSummary, Organization, RoleDefinition } from '@admin/model/admin';
import { ActorView } from '@admin/model/adminPredicates';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

const empty: AdminUserInput = { name: '', email: '', password: '', organization_id: null, department_id: null, phone: null, extension: null, job_title: null, is_active: true, roles: [] };

export function UserForm() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const actor = user as ActorView | null;
  const actorIsSuperAdmin = actor?.is_super_admin === true;
  const actorOrganizationId = actor?.organization_id ?? null;
  const { userId } = useParams();
  const navigate = useNavigate();
  const editing = userId !== undefined;
  const [form, setForm] = useState<AdminUserInput>({ ...empty, organization_id: actorOrganizationId });
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [departments, setDepartments] = useState<DepartmentSummary[]>([]);
  const [roles, setRoles] = useState<RoleDefinition[]>([]);
  const [rolesDirty, setRolesDirty] = useState(false);
  const [hasLockedSuperAdminRole, setHasLockedSuperAdminRole] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const departmentRequestGeneration = useRef(0);
  const invalidateDepartmentRequests = useCallback(() => { departmentRequestGeneration.current++; }, []);

  const loadDepartments = useCallback(async (organizationId: number | null) => {
    const generation = ++departmentRequestGeneration.current;
    setDepartments([]);
    if (!organizationId) return;
    try {
      const response = await adminApi.departments.summary(organizationId);
      if (generation === departmentRequestGeneration.current) setDepartments(response.data);
    } catch (caught) {
      if (generation === departmentRequestGeneration.current) setError(apiErrorMessage(caught, t('common.error')));
    }
  }, [t]);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        // Super admin keeps the cross-tenant organization catalog so the
        // selector stays usable. OrgSuper never makes this call — the actor's
        // organization is locked from the auth payload and the selector is
        // hidden entirely below.
        const organizationResponse = actorIsSuperAdmin ? await adminApi.organizations.all() : { data: [] as Organization[] };
        const roleResponse = await adminApi.roles.list();
        let next: AdminUserInput = { ...empty, organization_id: actorOrganizationId };
        if (editing) {
          const record = await adminApi.users.get(Number(userId));
          // OrgSuper cannot widen the target's organization — the form is
          // pinned to the actor's organization regardless of what the row
          // carries. Super admin keeps the existing flow.
          const lockedOrganizationId = actorIsSuperAdmin ? record.organization_id : actorOrganizationId;
          next = {
            name: record.name,
            email: record.email,
            password: '',
            organization_id: lockedOrganizationId,
            department_id: record.department?.id ?? record.department_id ?? null,
            phone: record.phone,
            extension: record.extension,
            job_title: record.job_title,
            is_active: record.is_active,
            roles: (record.roles ?? []).filter((role) => role !== 'super_admin'),
          };
          if (active) { setForm(next); setHasLockedSuperAdminRole((record.roles ?? []).includes('super_admin')); }
        }
        if (active) {
          setOrganizations(organizationResponse.data);
          setRoles(roleResponse.data.filter((role) => role.name !== 'super_admin'));
          setLoading(false);
          void loadDepartments(next.organization_id ?? null);
        }
      } catch (caught) {
        if (active) setError(apiErrorMessage(caught, t('users.load_error')));
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; invalidateDepartmentRequests(); };
  }, [actorIsSuperAdmin, actorOrganizationId, editing, invalidateDepartmentRequests, loadDepartments, t, userId]);

  const chooseOrganization = async (organizationId: number | null) => {
    if (!actorIsSuperAdmin) return;
    setForm((current) => ({ ...current, organization_id: organizationId, department_id: null }));
    await loadDepartments(organizationId);
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
      const payload: Partial<AdminUserInput> = { ...form, phone: form.phone || null, extension: form.extension || null, job_title: form.job_title || null };
      if (editing) {
        delete payload.organization_id;
        if (!payload.password) delete payload.password;
        if (!rolesDirty) delete payload.roles;
      }
      if (editing) await adminApi.users.update(Number(userId), payload); else await adminApi.users.create(payload);
      navigate('/users');
    } catch (caught) { setError(apiErrorMessage(caught, t('users.save_error'))); }
  };

  if (loading) return <p className="p-6">{t('common.loading')}</p>;
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconUser className="h-6 w-6" />} title={t(editing ? 'users.edit' : 'users.create_new')} />{error && <Alert variant="danger">{error}</Alert>}<Card><form className="grid gap-4 md:grid-cols-2" onSubmit={(event) => void submit(event)}>
    <Input aria-label={t('common.name')} label={t('common.name')} value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} error={errors.name} />
    <Input aria-label={t('common.email')} type="email" label={t('common.email')} value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} error={errors.email} />
    <Input aria-label={t('users.password')} type="password" label={t('users.password')} value={form.password ?? ''} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} error={errors.password} />
    {actorIsSuperAdmin && (
      <label className="text-sm">{t('admin.organizations.title')}<select aria-label={t('admin.organizations.title')} disabled={editing} className="mt-1 block w-full rounded-lg border p-2" value={form.organization_id ?? ''} onChange={(event) => void chooseOrganization(event.target.value ? Number(event.target.value) : null)}><option value="">{t('common.none')}</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>
    )}
    <label className="text-sm">{t('common.department')}<select aria-label={t('common.department')} className="mt-1 block w-full rounded-lg border p-2" value={form.department_id ?? ''} onChange={(event) => setForm((current) => ({ ...current, department_id: event.target.value ? Number(event.target.value) : null }))}><option value="">{t('users.select_department')}</option>{departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label>
    <Input label={t('common.phone')} value={form.phone ?? ''} onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))} />
    <Input label={t('users.extension')} value={form.extension ?? ''} onChange={(event) => setForm((current) => ({ ...current, extension: event.target.value }))} />
    <Input label={t('users.job_title')} value={form.job_title ?? ''} onChange={(event) => setForm((current) => ({ ...current, job_title: event.target.value }))} />
    <fieldset className="space-y-2 md:col-span-2"><legend className="text-sm font-medium">{t('users.roles')}</legend>{hasLockedSuperAdminRole && <p className="rounded-lg border p-3 text-sm">{t('admin.users.superAdminLocked')}</p>}{roles.map((role) => <label className="me-4 inline-flex items-center gap-2" key={role.id}><input type="checkbox" checked={form.roles.includes(role.name)} onChange={() => { setRolesDirty(true); setForm((current) => ({ ...current, roles: current.roles.includes(role.name) ? current.roles.filter((name) => name !== role.name) : [...current.roles, role.name] })); }} />{role.display_name}</label>)}</fieldset>
    <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} />{t('users.user_active')}</label>
    <div className="flex gap-2 md:col-span-2"><Button type="submit">{t(editing ? 'common.save_changes' : 'common.create')}</Button><Button type="button" variant="secondary" onClick={() => navigate('/users')}>{t('common.cancel')}</Button></div>
  </form></Card></div>;
}
