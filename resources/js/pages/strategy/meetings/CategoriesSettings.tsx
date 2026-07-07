import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconSettings, IconPlus, IconEdit, IconTrash, IconListTree } from '@tabler/icons-react';
import {
  Button,
  Badge,
  Card,
  Input,
  Switch,
  Modal,
  ModalBody,
  ModalFooter,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  Skeleton,
  DeleteConfirmationModal,
  PageHeader,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import { useToast } from '@shared/ui/Toast';
import { meetingCategoriesApi } from '@features/meetings/api';
import type { MeetingCategory } from '@features/meetings/types';

interface CategoryFormData {
  name: string;
  is_active: boolean;
  sort_order: number;
}

const emptyForm: CategoryFormData = { name: '', is_active: true, sort_order: 0 };

const MeetingCategoriesSettings: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [categories, setCategories] = useState<MeetingCategory[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const [formModal, setFormModal] = useState<{ open: boolean; category: MeetingCategory | null }>({ open: false, category: null });
  const [form, setForm] = useState<CategoryFormData>(emptyForm);
  const [errors, setErrors] = useState<{ name?: string }>({});
  const [isSaving, setIsSaving] = useState(false);

  const [deleteModal, setDeleteModal] = useState<{ open: boolean; category: MeetingCategory | null }>({ open: false, category: null });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchCategories = async () => {
    setIsLoading(true);
    try {
      const res = await meetingCategoriesApi.getAll();
      setCategories(res.data ?? []);
    } catch {
      showToast('error', t('common.error_occurred'));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchCategories();
  }, []);

  const openCreate = () => {
    setForm(emptyForm);
    setErrors({});
    setFormModal({ open: true, category: null });
  };

  const openEdit = (category: MeetingCategory) => {
    setForm({ name: category.name, is_active: category.is_active, sort_order: category.sort_order });
    setErrors({});
    setFormModal({ open: true, category });
  };

  const closeForm = () => {
    if (isSaving) return;
    setFormModal({ open: false, category: null });
    setErrors({});
  };

  const handleSave = async () => {
    if (!form.name.trim()) {
      setErrors({ name: t('common.required') });
      return;
    }
    setIsSaving(true);
    try {
      const payload = { name: form.name.trim(), is_active: form.is_active, sort_order: form.sort_order };
      if (formModal.category) {
        await meetingCategoriesApi.update(formModal.category.id, payload);
        showToast('success', t('meetings.categories.updated'));
      } else {
        await meetingCategoriesApi.create(payload);
        showToast('success', t('meetings.categories.created'));
      }
      setFormModal({ open: false, category: null });
      await fetchCategories();
    } catch {
      showToast('error', t('common.save_error'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteModal.category) return;
    setIsDeleting(true);
    try {
      await meetingCategoriesApi.delete(deleteModal.category.id);
      showToast('success', t('meetings.categories.deleted'));
      setDeleteModal({ open: false, category: null });
      await fetchCategories();
    } catch {
      showToast('error', t('common.error_occurred'));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-6">
      {!embedded && (
        <PageHeader
          title={t('meetings.categories.settings_title')}
          subtitle={t('meetings.categories.settings_subtitle')}
          icon={IconSettings}
        />
      )}

      <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 sm:px-6 py-4 border-b border-[var(--border-default)]">
          <div className="flex items-center gap-2">
            <IconListTree className="h-5 w-5 text-[var(--text-secondary)]" />
            <h2 className="text-base font-semibold text-[var(--text-primary)]">{t('meetings.categories.title')}</h2>
          </div>
          <Button onClick={openCreate} leftIcon={<IconPlus className="h-4 w-4" />}>
            {t('meetings.categories.add')}
          </Button>
        </div>

        {isLoading ? (
          <div className="p-6 space-y-4">
            {[...Array(4)].map((_, i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </div>
        ) : categories.length === 0 ? (
          <div className="text-center py-12 px-6">
            <IconListTree className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
            <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('meetings.categories.empty')}</h3>
            <Button onClick={openCreate} leftIcon={<IconPlus className="h-4 w-4" />}>
              {t('meetings.categories.add')}
            </Button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table hoverable>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('meetings.categories.name')}</TableHead>
                  <TableHead>{t('meetings.categories.sort_order')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="w-24 text-center">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {categories.map((category) => (
                  <TableRow key={category.id}>
                    <TableCell>
                      <span className="font-medium text-[var(--text-primary)]">{category.name}</span>
                    </TableCell>
                    <TableCell>
                      <span className="text-[var(--text-secondary)]">{category.sort_order}</span>
                    </TableCell>
                    <TableCell>
                      <Badge variant={category.is_active ? 'success' : 'default'} size="sm">
                        {category.is_active ? t('common.active') : t('common.inactive')}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-center gap-1">
                        <button
                          onClick={() => openEdit(category)}
                          className="p-2 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-colors"
                          title={t('common.edit')}
                          aria-label={t('common.edit')}
                        >
                          <IconEdit className="h-4 w-4" />
                        </button>
                        <IconButton
                          variant="danger"
                          onClick={() => setDeleteModal({ open: true, category })}
                          title={t('common.delete')}
                          aria-label={t('common.delete')}
                        >
                          <IconTrash className="h-4 w-4" />
                        </IconButton>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </Card>

      <Modal
        isOpen={formModal.open}
        onClose={closeForm}
        title={formModal.category ? t('meetings.categories.edit') : t('meetings.categories.add')}
        size="md"
      >
        <ModalBody>
          <div className="space-y-4">
            <Input
              label={t('meetings.categories.name')}
              value={form.name}
              onChange={(e) => {
                setForm((prev) => ({ ...prev, name: e.target.value }));
                if (errors.name) setErrors({});
              }}
              error={errors.name}
              required
            />
            <Input
              type="number"
              label={t('meetings.categories.sort_order')}
              value={String(form.sort_order)}
              onChange={(e) => setForm((prev) => ({ ...prev, sort_order: Number(e.target.value) }))}
              min={0}
            />
            <Switch
              label={t('common.active')}
              checked={form.is_active}
              onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
            />
          </div>
        </ModalBody>
        <ModalFooter>
          <Button variant="outline" onClick={closeForm} disabled={isSaving}>
            {t('common.cancel')}
          </Button>
          <Button onClick={handleSave} disabled={isSaving}>
            {isSaving ? t('common.loading') : t('common.save')}
          </Button>
        </ModalFooter>
      </Modal>

      <DeleteConfirmationModal
        isOpen={deleteModal.open}
        item={deleteModal.category}
        onClose={() => {
          if (!isDeleting) setDeleteModal({ open: false, category: null });
        }}
        onConfirm={handleDelete}
        title={t('common.confirm_delete')}
        itemName={deleteModal.category?.name ?? ''}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default MeetingCategoriesSettings;
