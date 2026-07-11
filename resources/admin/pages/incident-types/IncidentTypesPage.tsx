import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconListDetails, IconPlus } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { DepartmentSummary, IncidentType, IncidentTypeInput } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

const empty: IncidentTypeInput = {
  name: '',
  name_ar: '',
  is_active: true,
  requires_reportable_type: false,
};

export function IncidentTypesPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const organizationId = (user as { organization_id?: number | null } | null)?.organization_id ?? null;
  const [rows, setRows] = useState<IncidentType[]>([]);
  const [departments, setDepartments] = useState<DepartmentSummary[]>([]);
  const [governingId, setGoverningId] = useState<number | null>(null);
  const [governingPending, setGoverningPending] = useState(false);
  const [form, setForm] = useState<IncidentTypeInput>(empty);
  const [editing, setEditing] = useState<IncidentType | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [reportableParent, setReportableParent] = useState<IncidentType | null>(null);
  const [reportableForm, setReportableForm] = useState({ name: '', name_ar: '' });
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    void Promise.all([
      adminApi.incidentTypes.list({ include_inactive: true }),
      adminApi.governance.list(),
      organizationId ? adminApi.departments.summary(organizationId) : Promise.resolve({ data: [] }),
    ]).then(([types, rules, departmentResponse]) => {
      if (!active) return;
      setRows(types.data);
      setGoverningId(rules.data.find((rule) => rule.resource_type === 'ovr')?.governing_unit_id ?? null);
      setDepartments(departmentResponse.data);
    }).catch((caught) => {
      if (active) setError(apiErrorMessage(caught, t('ovr.load_error')));
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, [organizationId, t]);

  const openCreate = () => {
    setEditing(null);
    setForm(empty);
    setFieldError(null);
    setFormOpen(true);
  };

  const openEdit = (row: IncidentType) => {
    setEditing(row);
    setForm({
      name: row.name,
      name_ar: row.name_ar,
      is_active: row.is_active,
      requires_reportable_type: row.requires_reportable_type ?? false,
    });
    setFieldError(null);
    setFormOpen(true);
  };

  const save = async () => {
    if (!form.name.trim() || !form.name_ar.trim()) {
      setFieldError(t('common.required'));
      return;
    }
    setError(null);
    try {
      if (editing) {
        const response = await adminApi.incidentTypes.update(editing.id, form) as { data: IncidentType };
        setRows((current) => current.map((row) => row.id === editing.id ? response.data : row));
      } else {
        const response = await adminApi.incidentTypes.create(form) as { data: IncidentType };
        setRows((current) => current.some((row) => row.id === response.data.id) ? current : [...current, response.data]);
      }
      setFormOpen(false);
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.save_error')));
    }
  };

  const remove = async (row: IncidentType) => {
    if (!window.confirm(t('common.confirm_delete'))) return;
    try {
      await adminApi.incidentTypes.delete(row.id);
      setRows((current) => current.filter((candidate) => candidate.id !== row.id));
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error_occurred')));
    }
  };

  const addReportableType = async () => {
    if (!reportableParent || !reportableForm.name.trim() || !reportableForm.name_ar.trim()) return;
    try {
      const response = await adminApi.incidentTypes.addReportableType(reportableParent.id, reportableForm) as { data: { id: string; name: string; name_ar: string } };
      setRows((current) => current.map((row) => row.id === reportableParent.id
        ? { ...row, reportable_types: [...(row.reportable_types ?? []), response.data] }
        : row));
      setReportableParent(null);
      setReportableForm({ name: '', name_ar: '' });
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.save_error')));
    }
  };

  const chooseGoverning = async (next: number | null) => {
    if (governingPending) return;
    const previous = governingId;
    setGoverningId(next);
    setError(null);
    setGoverningPending(true);
    try {
      await adminApi.governance.update({ resource_type: 'ovr', governing_unit_id: next });
    } catch (caught) {
      setGoverningId(previous);
      setError(apiErrorMessage(caught, t('common.error')));
    } finally {
      setGoverningPending(false);
    }
  };

  return <div className="space-y-6 p-6" data-testid="admin-protected-page">
    <AdminPageHeader icon={<IconListDetails className="h-6 w-6" />} title={t('ovr.settings_title')} subtitle={t('ovr.settings_subtitle')} actions={<Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={openCreate}>{t('ovr.add_category')}</Button>} />
    {error && <Alert variant="danger">{error}</Alert>}
    <Card><label className="text-sm font-medium">{t('ovr.governing_department')}<select aria-label={t('ovr.governing_department')} disabled={governingPending} className="mt-2 block w-full rounded-lg border p-2" value={governingId ?? ''} onChange={(event) => void chooseGoverning(event.target.value ? Number(event.target.value) : null)}><option value="">{t('common.none')}</option>{departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label></Card>
    {formOpen && <Card><div className="grid gap-4 md:grid-cols-2"><Input aria-label={t('ovr.category_name')} label={t('ovr.category_name')} value={form.name} onChange={(event) => { setForm((current) => ({ ...current, name: event.target.value })); setFieldError(null); }} error={fieldError ?? undefined} /><Input aria-label={t('ovr.category_name_ar')} label={t('ovr.category_name_ar')} value={form.name_ar} onChange={(event) => { setForm((current) => ({ ...current, name_ar: event.target.value })); setFieldError(null); }} /><label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))} />{t('common.active')}</label><label className="flex items-center gap-2"><input type="checkbox" checked={form.requires_reportable_type} onChange={(event) => setForm((current) => ({ ...current, requires_reportable_type: event.target.checked }))} />{t('admin.incidentTypes.requiresReportableType')}</label><div className="flex gap-2 md:col-span-2"><Button onClick={() => void save()}>{t('common.save')}</Button><Button variant="secondary" onClick={() => setFormOpen(false)}>{t('common.cancel')}</Button></div></div></Card>}
    {reportableParent && <Card><h2 className="mb-4 font-semibold">{t('admin.incidentTypes.addReportableType')}</h2><div className="grid gap-4 md:grid-cols-2"><Input aria-label={t('admin.incidentTypes.reportableName')} label={t('admin.incidentTypes.reportableName')} value={reportableForm.name} onChange={(event) => setReportableForm((current) => ({ ...current, name: event.target.value }))} /><Input aria-label={t('admin.incidentTypes.reportableNameAr')} label={t('admin.incidentTypes.reportableNameAr')} value={reportableForm.name_ar} onChange={(event) => setReportableForm((current) => ({ ...current, name_ar: event.target.value }))} /><Button onClick={() => void addReportableType()}>{t('admin.incidentTypes.saveReportableType')}</Button></div></Card>}
    <Card>{loading ? <p>{t('common.loading')}</p> : rows.length === 0 ? <p>{t('ovr.no_categories')}</p> : <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-start">{t('ovr.category_name')}</th><th className="p-2 text-start">{t('ovr.category_name_ar')}</th><th className="p-2 text-start">{t('common.status')}</th><th className="p-2 text-start">{t('admin.incidentTypes.reportableTypes')}</th><th className="p-2 text-end">{t('common.actions')}</th></tr></thead><tbody>{rows.map((row) => <tr className="border-b" key={row.id}><td className="p-2">{row.name}</td><td className="p-2">{row.name_ar}</td><td className="p-2"><Badge variant={row.is_active ? 'success' : 'default'}>{t(row.is_active ? 'common.active' : 'common.inactive')}</Badge>{row.requires_reportable_type && <p className="mt-1 text-xs">{t('admin.incidentTypes.requiresReportableType')}</p>}</td><td className="p-2"><ul>{(row.reportable_types ?? []).map((subtype) => <li key={subtype.id}>{subtype.name}</li>)}</ul></td><td className="p-2 text-end"><div className="flex justify-end gap-2"><Button size="sm" variant="secondary" onClick={() => { setReportableParent(row); setReportableForm({ name: '', name_ar: '' }); }}>{t('admin.incidentTypes.addReportableType')}</Button><Button size="sm" variant="secondary" onClick={() => openEdit(row)}>{t('common.edit')}</Button><Button size="sm" variant="danger" onClick={() => void remove(row)}>{t('common.delete')}</Button></div></td></tr>)}</tbody></table></div>}</Card>
  </div>;
}
