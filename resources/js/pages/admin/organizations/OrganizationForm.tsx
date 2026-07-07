import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import {
  organizationsApi,
  Organization,
  OrganizationType,
  ORGANIZATION_TYPES,
} from '@entities/admin';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Textarea } from '@shared/ui/Textarea';
import { Checkbox } from '@shared/ui/Checkbox';
import { Select, SelectOption } from '@shared/ui/Select';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import DeleteConfirmationModal from '@shared/ui/DeleteConfirmationModal';
import {IconBuilding, IconDeviceFloppy, IconLoader, IconTrash, IconX} from '@tabler/icons-react';

const TYPE_KEYS: Record<OrganizationType, string> = {
  cluster: 'admin.organizations.types.cluster',
  hospital: 'admin.organizations.types.hospital',
  center: 'admin.organizations.types.center',
  organization: 'admin.organizations.types.organization',
  other: 'admin.organizations.types.other',
};

export const OrganizationForm: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id?: string }>();
  const isEdit = !!id && id !== 'new';

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [allOrgs, setAllOrgs] = useState<Organization[]>([]);

  const [form, setForm] = useState<Partial<Organization>>({
    name: '',
    code: '',
    type: 'organization',
    parent_id: null,
    sort_order: 0,
    email: '',
    phone: '',
    address: '',
    website: '',
    description: '',
    is_active: true,
  });

  useEffect(() => {
    (async () => {
      try {
        const result = await organizationsApi.list({ per_page: 100 });
        const items = ((result as any).data || []) as Organization[];
        setAllOrgs(items.filter((o) => isEdit ? o.id !== Number(id) : true));
      } catch {
        // غير حرج — لا نمنع الحفظ بسبب فشل تحميل قائمة الـ parents
      }
    })();
  }, [id, isEdit]);

  useEffect(() => {
    if (!isEdit) return;
    (async () => {
      try {
        const result = await organizationsApi.get(Number(id));
        setForm((result as any).data || {});
      } catch (err: any) {
        setError(err?.message || t('common.error'));
      } finally {
        setLoading(false);
      }
    })();
  }, [id, isEdit, t]);

  const typeOptions: SelectOption[] = useMemo(
    () => ORGANIZATION_TYPES.map((value) => ({
      value,
      label: t(TYPE_KEYS[value]),
    })),
    [t],
  );

  /**
   * قائمة المؤسسات الصالحة كأب للمؤسسة الحالية:
   *   - فقط التي allowed_child_types تحتوي على type الحالي
   *   - لا نفس الـ id (لتجنّب self-reference في وضع التعديل)
   *   - فقط الفعّالة (is_active = true)
   */
  const availableParents: SelectOption[] = useMemo(() => {
    const currentType = (form.type as OrganizationType) || 'organization';
    if (currentType === 'cluster') return [];

    return allOrgs
      .filter((o) => o.is_active)
      .filter((o) => Array.isArray(o.allowed_child_types) && o.allowed_child_types.includes(currentType))
      .filter((o) => !isEdit || o.id !== Number(id))
      .map((o) => ({
        value: String(o.id),
        label: `${o.name} (${o.code})`,
      }));
  }, [allOrgs, form.type, id, isEdit]);

  // عند تغيّر type إلى cluster، امسح parent_id
  useEffect(() => {
    if (form.type === 'cluster' && form.parent_id !== null) {
      setForm((f) => ({ ...f, parent_id: null }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.type]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      if (isEdit) {
        await organizationsApi.update(Number(id), form);
      } else {
        await organizationsApi.create(form);
      }
      navigate('/admin/organizations');
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!isEdit) return;
    setDeleting(true);
    try {
      await organizationsApi.delete(Number(id));
      navigate('/admin/organizations');
    } catch (err: any) {
      setError(err?.message || t('common.error'));
      setDeleting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
      </div>
    );
  }

  return (
    <div className="p-6 max-w-3xl mx-auto">
      <form onSubmit={handleSubmit}>
        <PageHeader
        icon={IconBuilding}
        iconTone="admin"
        title={
          isEdit
            ? t('admin.organizations.editTitle')
            : t('admin.organizations.addTitle')
        }
        className="mb-6"
        actions={
          <div className="flex gap-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => navigate('/admin/organizations')}
            >
              <IconX className="w-4 h-4 me-2" />
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={saving}>
              <IconDeviceFloppy className="w-4 h-4 me-2" />
              {saving ? t('common.saving') : t('common.save')}
            </Button>
          </div>
        }
      />

        <Card className="p-6 space-y-4">
          {error && (
            <Alert variant="danger">{error}</Alert>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.name')} *
              </label>
              <Input
                required
                value={form.name || ''}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.code')} *
              </label>
              <Input
                required
                value={form.code || ''}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
                className="font-mono"
              />
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.type')}
              </label>
              <Select
                options={typeOptions}
                value={(form.type as string) || 'organization'}
                onChange={(e) => setForm({ ...form, type: e.target.value as OrganizationType })}
                placeholder={t('admin.organizations.fields.type')}
              />
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.sortOrder')}
              </label>
              <Input
                type="number"
                min={0}
                value={form.sort_order ?? 0}
                onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })}
              />
            </div>

            {form.type !== 'cluster' && (
              <div className="md:col-span-2">
                <label className="block text-sm font-medium mb-1">
                  {t('admin.organizations.fields.parent')}
                </label>
                <Select
                  options={[
                    { value: '', label: t('admin.organizations.fields.parentPlaceholder') },
                    ...availableParents,
                  ]}
                  value={form.parent_id !== null && form.parent_id !== undefined ? String(form.parent_id) : ''}
                  onChange={(e) => setForm({ ...form, parent_id: e.target.value ? Number(e.target.value) : null })}
                  placeholder={t('admin.organizations.fields.parent')}
                />
              </div>
            )}

            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.email')}
              </label>
              <Input
                type="email"
                value={form.email || ''}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.phone')}
              </label>
              <Input
                value={form.phone || ''}
                onChange={(e) => setForm({ ...form, phone: e.target.value })}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.website')}
              </label>
              <Input
                type="url"
                value={form.website || ''}
                onChange={(e) => setForm({ ...form, website: e.target.value })}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.address')}
              </label>
              <Input
                value={form.address || ''}
                onChange={(e) => setForm({ ...form, address: e.target.value })}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium mb-1">
                {t('admin.organizations.fields.description')}
              </label>
              <Textarea
                value={form.description || ''}
                onChange={(e) =>
                  setForm({ ...form, description: e.target.value })
                }
                rows={3}
              />
            </div>
            <div className="md:col-span-2">
              <Checkbox
                checked={form.is_active ?? true}
                onChange={(e) =>
                  setForm({ ...form, is_active: e.target.checked })
                }
                label={t('admin.organizations.fields.isActive')}
              />
            </div>

            {isEdit && (
              <div className="md:col-span-2 pt-2 border-t border-[var(--border)]">
                <div className="text-sm text-[var(--text-secondary)]">
                  {t('admin.organizations.fields.childrenCount')}:{' '}
                  <span className="font-semibold text-[var(--text-primary)]">
                    {form.children_count ?? 0}
                  </span>
                </div>
              </div>
            )}
          </div>

          {isEdit && (
            <div className="flex items-center pt-4 border-t border-[var(--border)]">
              <Button
                type="button"
                variant="danger"
                onClick={() => setShowDeleteModal(true)}
                disabled={deleting}
              >
                <IconTrash className="w-4 h-4 me-2" />
                {deleting ? t('common.deleting') : t('common.delete')}
              </Button>
            </div>
          )}
        </Card>
      </form>

      <DeleteConfirmationModal<Organization>
        isOpen={showDeleteModal}
        item={isEdit ? (form as Organization) : null}
        title={t('common.confirm_delete')}
        itemName={form.name || ''}
        warningMessage={t('admin.organizations.confirmDelete')}
        confirmButtonText={t('common.delete')}
        isDeleting={deleting}
        onClose={() => !deleting && setShowDeleteModal(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
};

export default OrganizationForm;
