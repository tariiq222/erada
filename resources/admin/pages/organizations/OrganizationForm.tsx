import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconBuilding } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { Organization, OrganizationInput, OrganizationType } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';

const emptyForm: OrganizationInput = { name: '', code: '', type: 'organization', parent_id: null, description: null, email: null, phone: null, address: null, website: null, is_active: true, sort_order: 0 };
const types: OrganizationType[] = ['cluster', 'hospital', 'center', 'organization', 'other'];

export function OrganizationForm() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { organizationId } = useParams();
  const editing = organizationId !== undefined;
  const [form, setForm] = useState<OrganizationInput>(emptyForm);
  const [parents, setParents] = useState<Organization[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        if (editing) {
          const response = await adminApi.organizations.get(Number(organizationId));
          if (active) setForm({ name: response.data.name, code: response.data.code, type: response.data.type, parent_id: response.data.parent_id, description: response.data.description, email: response.data.email, phone: response.data.phone, address: response.data.address, website: response.data.website, is_active: response.data.is_active, sort_order: response.data.sort_order });
        }
        const response = await adminApi.organizations.list({ per_page: 100 });
        if (active) setParents(response.data.filter((row) => row.id !== Number(organizationId)));
      } catch (caught) {
        if (active) setError(apiErrorMessage(caught, t('common.error')));
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
  }, [editing, organizationId, t]);

  const text = (key: keyof OrganizationInput) => (event: ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [key]: event.target.value || null }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const nextErrors: Record<string, string> = {};
    if (!form.name.trim()) nextErrors.name = t('validation.required');
    if (!form.code.trim()) nextErrors.code = t('validation.required');
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;
    setError(null);
    try {
      if (editing) await adminApi.organizations.update(Number(organizationId), form);
      else await adminApi.organizations.create(form);
      navigate('/organizations');
    } catch (caught) { setError(apiErrorMessage(caught, t('common.error'))); }
  };

  if (loading) return <p className="p-6">{t('common.loading')}</p>;
  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader icon={<IconBuilding className="h-6 w-6" />} title={t(editing ? 'admin.organizations.editTitle' : 'admin.organizations.addTitle')} />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card><form className="grid gap-4 md:grid-cols-2" onSubmit={(event) => void submit(event)}>
        <Input aria-label={t('admin.organizations.fields.name')} aria-required="true" label={t('admin.organizations.fields.name')} value={form.name ?? ''} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} error={errors.name} />
        <Input aria-label={t('admin.organizations.fields.code')} aria-required="true" label={t('admin.organizations.fields.code')} value={form.code ?? ''} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))} error={errors.code} />
        <label className="text-sm">{t('admin.organizations.fields.type')}<select className="mt-1 block w-full rounded-lg border p-2" value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value as OrganizationType }))}>{types.map((type) => <option key={type} value={type}>{t(`admin.organizations.types.${type}`)}</option>)}</select></label>
        <label className="text-sm">{t('admin.organizations.fields.parent')}<select className="mt-1 block w-full rounded-lg border p-2" value={form.parent_id ?? ''} onChange={(event) => setForm((current) => ({ ...current, parent_id: event.target.value ? Number(event.target.value) : null }))}><option value="">{t('admin.organizations.fields.parentPlaceholder')}</option>{parents.map((parent) => <option key={parent.id} value={parent.id}>{parent.name}</option>)}</select></label>
        <Input label={t('admin.organizations.fields.email')} type="email" value={form.email ?? ''} onChange={text('email')} />
        <Input label={t('admin.organizations.fields.phone')} value={form.phone ?? ''} onChange={text('phone')} />
        <Input label={t('admin.organizations.fields.website')} type="url" value={form.website ?? ''} onChange={text('website')} />
        <Input label={t('admin.organizations.fields.sortOrder')} type="number" min={0} value={form.sort_order} onChange={(event) => setForm((current) => ({ ...current, sort_order: Number(event.target.value) }))} />
        <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} />{t('admin.organizations.fields.isActive')}</label>
        <div className="flex gap-2 md:col-span-2"><Button type="submit">{t(editing ? 'common.save_changes' : 'common.create')}</Button><Button type="button" variant="secondary" onClick={() => navigate('/organizations')}>{t('common.cancel')}</Button></div>
      </form></Card>
    </div>
  );
}
