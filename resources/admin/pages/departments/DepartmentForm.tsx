import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconNetwork } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AdminDepartmentInput, AdminUser, DepartmentSummary, Organization } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

const empty: AdminDepartmentInput = { name: '', code: null, description: null, parent_id: null, level: 1, manager_id: null, is_active: true, organization_id: null };

export function DepartmentForm() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { departmentId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const editing = departmentId !== undefined;
  const requestedOrganizationId = searchParams.get('organization_id');
  const initialOrganizationId = requestedOrganizationId ? Number(requestedOrganizationId) : (user as { organization_id?: number | null } | null)?.organization_id ?? null;
  const [form, setForm] = useState<AdminDepartmentInput>({ ...empty, organization_id: initialOrganizationId });
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [parents, setParents] = useState<DepartmentSummary[]>([]);
  const [managers, setManagers] = useState<AdminUser[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const choiceGeneration = useRef(0);

  const loadOrganizationChoices = async (organizationId: number | null) => {
    const generation = ++choiceGeneration.current;
    if (organizationId === null) {
      setParents([]);
      setManagers([]);
      return;
    }
    try {
      const [parentResponse, managerResponse] = await Promise.all([
        adminApi.departments.summary(organizationId),
        adminApi.users.all(organizationId),
      ]);
      if (generation !== choiceGeneration.current) return;
      setParents(parentResponse.data.filter((row) => row.id !== Number(departmentId)));
      setManagers(managerResponse.data);
    } catch (caught) {
      if (generation !== choiceGeneration.current) return;
      setParents([]);
      setManagers([]);
      setError(apiErrorMessage(caught, t('hr.departments_load_error')));
    }
  };

  useEffect(() => { let active = true; (async () => { try { const organizationResponse = await adminApi.organizations.all(); let next = form; if (editing) { const record = await adminApi.departments.get(Number(departmentId)); next = { name: record.name, code: record.code, description: record.description, parent_id: record.parent_id, level: record.level, manager_id: record.manager_id, is_active: record.is_active, organization_id: record.organization_id }; if (active) setForm(next); } if (active) { setOrganizations(organizationResponse.data); setLoading(false); void loadOrganizationChoices(next.organization_id ?? null); } } catch (caught) { if (active) { setError(apiErrorMessage(caught, t('hr.departments_load_error'))); setLoading(false); } } })(); return () => { active = false; choiceGeneration.current += 1; }; // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [departmentId, editing, t]);

  const chooseOrganization = async (organizationId: number | null) => {
    setForm((current) => ({ ...current, organization_id: organizationId, parent_id: null }));
    setError(null);
    setManagers([]);
    await loadOrganizationChoices(organizationId);
  };

  const submit = async (event: FormEvent) => { event.preventDefault(); const nextErrors: Record<string, string> = {}; if (!form.name.trim()) nextErrors.name = t('common.required'); if (!form.level) nextErrors.level = t('common.required'); setErrors(nextErrors); if (Object.keys(nextErrors).length) return; setError(null); try { const payload = { ...form, code: form.code || null, description: form.description || null }; if (editing) { delete payload.organization_id; await adminApi.departments.update(Number(departmentId), payload); } else await adminApi.departments.create(payload); navigate(`/departments${form.organization_id ? `?organization_id=${form.organization_id}` : ''}`); } catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); } };
  if (loading) return <p className="p-6">{t('common.loading')}</p>;
  return <div className="space-y-6 p-6" data-testid="admin-protected-page"><AdminPageHeader icon={<IconNetwork className="h-6 w-6" />} title={t(editing ? 'hr.edit_department' : 'hr.create_department')} />{error && <Alert variant="danger">{error}</Alert>}<Card><form className="grid gap-4 md:grid-cols-2" onSubmit={(event) => void submit(event)}><Input aria-label={t('hr.department_name')} label={t('hr.department_name')} value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} error={errors.name} /><Input label={t('hr.department_code')} value={form.code ?? ''} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))} />
    <label className="text-sm">{t('admin.organizations.title')}<select disabled={editing} className="mt-1 block w-full rounded-lg border p-2" value={form.organization_id ?? ''} onChange={(event) => void chooseOrganization(event.target.value ? Number(event.target.value) : null)}><option value="">{t('common.none')}</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>
    <label className="text-sm">{t('hr.parent_department')}<select className="mt-1 block w-full rounded-lg border p-2" value={form.parent_id ?? ''} onChange={(event) => setForm((current) => ({ ...current, parent_id: event.target.value ? Number(event.target.value) : null }))}><option value="">{t('hr.no_parent_department')}</option>{parents.map((parent) => <option key={parent.id} value={parent.id}>{parent.name}</option>)}</select></label>
    <label className="text-sm">{t('hr.department_level')}<select aria-label={t('hr.department_level')} className="mt-1 block w-full rounded-lg border p-2" value={form.level} onChange={(event) => setForm((current) => ({ ...current, level: Number(event.target.value) }))}>{[1, 2, 3, 4, 5, 6].map((level) => <option key={level} value={level}>{level}</option>)}</select>{errors.level && <span className="text-sm text-red-600">{errors.level}</span>}</label>
    <label className="text-sm">{t('hr.department_manager')}<select aria-label={t('hr.department_manager')} className="mt-1 block w-full rounded-lg border p-2" value={form.manager_id ?? ''} onChange={(event) => setForm((current) => ({ ...current, manager_id: event.target.value ? Number(event.target.value) : null }))}><option value="">{t('hr.no_manager')}</option>{managers.map((manager) => <option key={manager.id} value={manager.id}>{manager.name}</option>)}</select></label>
    <Input className="md:col-span-2" label={t('common.description')} value={form.description ?? ''} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} />
    <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} />{t('hr.department_status')}</label>
    <div className="flex gap-2 md:col-span-2"><Button type="submit">{t(editing ? 'common.save_changes' : 'common.create')}</Button><Button type="button" variant="secondary" onClick={() => navigate('/departments')}>{t('common.cancel')}</Button></div>
  </form></Card></div>;
}
