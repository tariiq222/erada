import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconSettings as SettingsIcon, IconPlus, IconEdit, IconTrash, IconListTree, IconSitemap} from '@tabler/icons-react';
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
  Pagination,
  Skeleton,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  DeleteConfirmationModal,
  PageHeader,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import { useToast } from '@shared/ui/Toast';
import { SkipToMain } from '@shared/ui/SkipToMain';
import { incidentCategoriesApi } from '@entities/incident';
import { api } from '@shared/api/client';
import { useAuth } from '@shared/contexts/AuthContext';
import OvrGoverningDepartmentSection from './components/OvrGoverningDepartmentSection';

interface IncidentCategory {
  id: number;
  name: string;
  name_ar?: string | null;
  is_active: boolean;
}

interface CategoryFormData {
  name: string;
  name_ar: string;
  is_active: boolean;
}

const PER_PAGE = 10;

const emptyForm: CategoryFormData = {
  name: '',
  name_ar: '',
  is_active: true,
};

const IconSettings: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const { canAccess } = useAuth();

  const [categories, setCategories] = useState<IncidentCategory[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [tab, setTab] = useState('categories');
  // Phase 9.3 freeze cleanup (2026-07-06): governing-department tab is gated
  // on the canonical `core.view_organizations` capability (umbrella org-read
  // gate). The legacy `manage_organization` transition-only string resolves
  // to `false` from Phase 9.3 onward and is kept in TRANSITION_ONLY_PERMISSIONS
  // only to fail loudly for any stray consumer.
  const canGovern = canAccess({ permission: 'core.view_organizations' });

  const [formModal, setFormModal] = useState<{ open: boolean; category: IncidentCategory | null }>({
    open: false,
    category: null,
  });
  const [form, setForm] = useState<CategoryFormData>(emptyForm);
  const [errors, setErrors] = useState<{ name?: string }>({});
  const [isSaving, setIsSaving] = useState(false);

  const [deleteModal, setDeleteModal] = useState<{ open: boolean; category: IncidentCategory | null }>({
    open: false,
    category: null,
  });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchCategories = async () => {
    setIsLoading(true);
    try {
      const res = (await incidentCategoriesApi.getAll()) as IncidentCategory[] | { data: IncidentCategory[] };
      setCategories(Array.isArray(res) ? res : (res?.data ?? []));
    } catch {
      showToast('error', t('ovr.load_error'));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchCategories();
  }, []);

  const lastPage = Math.max(1, Math.ceil(categories.length / PER_PAGE));
  const pagedCategories = categories.slice((currentPage - 1) * PER_PAGE, currentPage * PER_PAGE);

  useEffect(() => {
    if (currentPage > lastPage) {
      setCurrentPage(lastPage);
    }
  }, [lastPage, currentPage]);

  const openCreate = () => {
    setForm(emptyForm);
    setErrors({});
    setFormModal({ open: true, category: null });
  };

  const openEdit = (category: IncidentCategory) => {
    setForm({
      name: category.name ?? '',
      name_ar: category.name_ar ?? '',
      is_active: category.is_active,
    });
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
      const payload = {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        is_active: form.is_active,
      };

      if (formModal.category) {
        await api.put(`/ovr/categories/${formModal.category.id}`, payload);
        showToast('success', t('ovr.category_updated'));
      } else {
        await api.post('/ovr/categories', payload);
        showToast('success', t('ovr.category_created'));
      }

      setFormModal({ open: false, category: null });
      setErrors({});
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
      await api.delete(`/ovr/categories/${deleteModal.category.id}`);
      showToast('success', t('ovr.category_deleted'));
      setDeleteModal({ open: false, category: null });
      await fetchCategories();
    } catch {
      showToast('error', t('common.error_occurred'));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div id="main-content" className="space-y-6">
      <SkipToMain label={t('a11y.skip_to_main')} />
      {/* Header */}
      <PageHeader
        title={t('ovr.settings_title')}
        subtitle={t('ovr.settings_subtitle')}
        icon={SettingsIcon}
        iconTone="risk"
      />

      <Tabs value={tab} onValueChange={setTab} defaultValue="categories">
        <TabsList>
          <TabsTrigger value="categories" icon={<IconListTree className="h-4 w-4" />}>
            {t('ovr.incident_types')}
          </TabsTrigger>
          {canGovern && (
            <TabsTrigger value="governing" icon={<IconSitemap className="h-4 w-4" />}>
              {t('ovr.governing_department')}
            </TabsTrigger>
          )}
        </TabsList>

        <TabsContent value="categories">
      {/* Categories Card */}
      <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
        {/* Card header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 sm:px-6 py-4 border-b border-[var(--border-default)]">
          <div className="flex items-center gap-2">
            <IconListTree className="h-5 w-5 text-[var(--support-amber-text)]" />
            <h2 className="text-base font-semibold text-[var(--text-primary)]">{t('ovr.incident_types')}</h2>
          </div>
          <Button onClick={openCreate} leftIcon={<IconPlus className="h-4 w-4" />}>
            {t('ovr.add_category')}
          </Button>
        </div>

        {isLoading ? (
          <div className="p-6 space-y-4">
            {[...Array(5)].map((_, i) => (
              <div key={i} className="flex items-center gap-4">
                <Skeleton className="h-10 w-10 rounded-lg" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-48" />
                  <Skeleton className="h-3 w-32" />
                </div>
              </div>
            ))}
          </div>
        ) : categories.length === 0 ? (
          <div className="text-center py-12 px-6">
            <IconListTree className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
            <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('ovr.no_categories')}</h3>
            <p className="text-[var(--text-tertiary)] mb-4">{t('ovr.no_categories_hint')}</p>
            <Button onClick={openCreate} leftIcon={<IconPlus className="h-4 w-4" />}>
              {t('ovr.add_category')}
            </Button>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table hoverable>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('ovr.category_name')}</TableHead>
                    <TableHead>{t('ovr.category_name_ar')}</TableHead>
                    <TableHead>{t('common.status')}</TableHead>
                    <TableHead className="w-24 text-center">{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {pagedCategories.map((category) => (
                    <TableRow key={category.id}>
                      <TableCell>
                        <span className="font-medium text-[var(--text-primary)]">{category.name}</span>
                      </TableCell>
                      <TableCell>
                        {category.name_ar ? (
                          <span className="text-[var(--text-secondary)]">{category.name_ar}</span>
                        ) : (
                          <span className="text-[var(--text-tertiary)]">-</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant={category.is_active ? 'success' : 'default'} size="sm">
                          {category.is_active ? t('common.active') : t('common.inactive')}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-1">
                          <IconButton
                            onClick={() => openEdit(category)}
                            variant="default"
                            title={t('common.edit')}
                            aria-label={t('common.edit')}
                          >
                            <IconEdit className="h-4 w-4" />
                          </IconButton>
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
            {lastPage > 1 && (
              <div className="p-4 border-t border-[var(--border-default)]">
                <Pagination
                  currentPage={currentPage}
                  totalPages={lastPage}
                  onPageChange={setCurrentPage}
                />
              </div>
            )}
          </>
        )}
      </Card>
        </TabsContent>

        {canGovern && (
          <TabsContent value="governing">
            <OvrGoverningDepartmentSection />
          </TabsContent>
        )}
      </Tabs>

      {/* Create / IconEdit Modal */}
      <Modal
        isOpen={formModal.open}
        onClose={closeForm}
        title={formModal.category ? t('ovr.edit_category') : t('ovr.add_category')}
        size="md"
      >
        <ModalBody>
          <div className="space-y-4">
            <Input
              label={t('ovr.category_name')}
              value={form.name}
              onChange={(e) => {
                setForm((prev) => ({ ...prev, name: e.target.value }));
                if (errors.name) setErrors({});
              }}
              error={errors.name}
              required
              placeholder={t('ovr.category_name_placeholder')}
            />
            <Input
              label={t('ovr.category_name_ar')}
              value={form.name_ar}
              onChange={(e) => setForm((prev) => ({ ...prev, name_ar: e.target.value }))}
              placeholder={t('ovr.category_name_ar_placeholder')}
            />
            <Switch
              label={t('common.active')}
              description={t('ovr.category_active_hint')}
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

      {/* Delete Confirm Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.open}
        item={deleteModal.category}
        onClose={() => {
          if (!isDeleting) setDeleteModal({ open: false, category: null });
        }}
        onConfirm={handleDelete}
        title={t('common.confirm_delete')}
        itemName={deleteModal.category?.name ?? ''}
        itemSubtitle={t('ovr.incident_type')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default IconSettings;
