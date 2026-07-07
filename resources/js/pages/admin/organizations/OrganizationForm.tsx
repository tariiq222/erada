import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { organizationsApi, Organization } from '@entities/admin';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Textarea } from '@shared/ui/Textarea';
import { Checkbox } from '@shared/ui/Checkbox';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import DeleteConfirmationModal from '@shared/ui/DeleteConfirmationModal';
import {IconBuilding, IconDeviceFloppy, IconLoader, IconTrash, IconX} from '@tabler/icons-react';

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

  const [form, setForm] = useState<Partial<Organization>>({
    name: '',
    code: '',
    email: '',
    phone: '',
    address: '',
    website: '',
    description: '',
    is_active: true,
  });

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
