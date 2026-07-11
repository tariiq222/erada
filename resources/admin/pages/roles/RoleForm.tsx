import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconShield } from '@tabler/icons-react';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { AbilityRegistry, Reach, RoleInput } from '@admin/model/admin';
import { AdminPageHeader as PageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';

interface RoleFormState {
  name: string;
  label_ar: string;
  label_en: string;
  permissions_capabilities: string[];
  reach: Record<string, Reach>;
}

const initial: RoleFormState = {
  name: '',
  label_ar: '',
  label_en: '',
  permissions_capabilities: [],
  reach: {},
};

export function RoleForm() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { roleId } = useParams();
  const editing = roleId !== undefined;
  const [form, setForm] = useState<RoleFormState>(initial);
  const [abilities, setAbilities] = useState<AbilityRegistry['groups']>([]);
  const [nameError, setNameError] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    void (async () => {
      try {
        const abilityResponse = await adminApi.roles.abilities();
        if (!active) return;
        setAbilities(abilityResponse.data.groups);

        if (editing) {
          const roleResponse = await adminApi.roles.get(Number(roleId));
          if (!active) return;
          const role = roleResponse.data;
          setForm({
            name: role.name,
            label_ar: role.label_ar ?? '',
            label_en: role.label_en ?? '',
            permissions_capabilities: role.capabilities ?? role.permissions,
            reach: role.reach ?? {},
          });
        }
      } catch (caught) {
        if (active) setError(apiErrorMessage(caught, t('common.error')));
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => { active = false; };
  }, [editing, roleId, t]);

  const toggle = (ability: string) => setForm((current) => ({
    ...current,
    permissions_capabilities: current.permissions_capabilities.includes(ability)
      ? current.permissions_capabilities.filter((item) => item !== ability)
      : [...current.permissions_capabilities, ability],
  }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!form.name.trim()) {
      setNameError(t('validation.required'));
      return;
    }

    setNameError('');
    const payload: RoleInput = { ...form };
    try {
      if (editing) await adminApi.roles.update(Number(roleId), payload);
      else await adminApi.roles.create({ ...payload, scope_type: 'organization' });
      navigate('/roles');
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.error')));
    }
  };

  if (loading) return <p className="p-6">{t('common.loading')}</p>;

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <PageHeader icon={IconShield} iconTone="admin" title={t(editing ? 'admin.roles.editTitle' : 'admin.roles.addTitle')} />
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form className="space-y-5" onSubmit={(event) => void submit(event)}>
          <div className="grid gap-4 md:grid-cols-2">
            <Input aria-label={t('admin.roles.fields.name')} aria-required="true" label={t('admin.roles.fields.name')} value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} error={nameError} />
            <Input aria-label={t('admin.roles.fields.labelAr')} label={t('admin.roles.fields.labelAr')} value={form.label_ar} onChange={(event) => setForm((current) => ({ ...current, label_ar: event.target.value }))} />
            <Input aria-label={t('admin.roles.fields.labelEn')} label={t('admin.roles.fields.labelEn')} value={form.label_en} onChange={(event) => setForm((current) => ({ ...current, label_en: event.target.value }))} />
            <div className="text-sm">
              <span className="block font-medium">{t('admin.roles.fields.scope')}</span>
              <p className="mt-1 rounded-lg border p-2">{t('admin.roles.fields.scopeOrganization')}</p>
            </div>
          </div>
          {abilities.map((group) => (
            <fieldset key={group.key} className="rounded-lg border p-4">
              <legend className="px-2 font-semibold">{group.label}</legend>
              <div className="grid gap-2 sm:grid-cols-2">
                {group.abilities.map((ability) => (
                  <label key={ability.id} className="flex items-center gap-2">
                    <input type="checkbox" checked={form.permissions_capabilities.includes(ability.id)} onChange={() => toggle(ability.id)} />
                    {ability.label}
                  </label>
                ))}
              </div>
              <label className="mt-3 block text-sm">
                {group.label} {t('admin.roles.reach.label')}
                <select aria-label={`${group.label} ${t('admin.roles.reach.label')}`} className="mt-1 block w-full rounded-lg border p-2" value={form.reach[group.key] ?? 'all'} onChange={(event) => setForm((current) => ({ ...current, reach: { ...current.reach, [group.key]: event.target.value as Reach } }))}>
                  <option value="own">{t('admin.roles.reach.own')}</option>
                  <option value="department">{t('admin.roles.reach.department')}</option>
                  <option value="all">{t('admin.roles.reach.all')}</option>
                </select>
              </label>
            </fieldset>
          ))}
          <div className="flex gap-2">
            <Button type="submit">{t(editing ? 'common.save_changes' : 'common.create')}</Button>
            <Button type="button" variant="secondary" onClick={() => navigate('/roles')}>{t('common.cancel')}</Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
