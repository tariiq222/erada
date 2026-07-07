import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUserCheck, IconUserPlus, IconList, IconLayoutGrid, IconBuilding, IconTrash, IconEye, IconPencil} from '@tabler/icons-react';
import { projectsApi } from '@entities/project';
import { useToast } from '@shared/ui/Toast';
import {
  Card,
  CardContent,
  Button,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  DeleteConfirmationModal,
  EmptyState,
} from '@shared/ui';
import SectionHeader from '@shared/ui/SectionHeader';
import { AddStakeholderModal, ViewEditStakeholderModal } from '../modals';
import { stakeholderRoleLabels } from '../../constants';
import type { ProjectDetails } from '../../types';

interface Stakeholder {
  id: number;
  name: string;
  role: string;
  organization?: string | null;
  email?: string | null;
  phone?: string | null;
  influence?: string;
}

interface StakeholdersSectionProps {
  stakeholders: ProjectDetails['stakeholders'];
  projectId: number;
  onStakeholderAdded?: () => void;
  onStakeholderRemoved?: () => void;
  onStakeholderUpdated?: () => void;
  canEdit?: boolean;
}

const StakeholdersSection: React.FC<StakeholdersSectionProps> = ({ stakeholders, projectId, onStakeholderAdded, onStakeholderRemoved, onStakeholderUpdated, canEdit = true }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');
  const [formData, setFormData] = useState({
    name: '',
    role: '',
    organization: '',
    email: '',
    phone: '',
  });

  // View/Edit Modal State
  const [viewEditModalOpen, setViewEditModalOpen] = useState(false);
  const [viewEditMode, setViewEditMode] = useState<'view' | 'edit'>('view');
  const [selectedStakeholder, setSelectedStakeholder] = useState<Stakeholder | null>(null);
  const [isUpdating, setIsUpdating] = useState(false);

  // Delete confirmation modal state
  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    stakeholderId: number | null;
    stakeholderName: string;
  }>({ isOpen: false, stakeholderId: null, stakeholderName: '' });
  const [isDeleting, setIsDeleting] = useState(false);

  const handleAddStakeholder = async () => {
    if (!formData.name || !formData.role) {
      showToast('error', t('projects.stakeholder_name_role_required'));
      return;
    }

    setIsLoading(true);
    try {
      await projectsApi.addStakeholder(projectId, formData);
      showToast('success', t('projects.stakeholder_added_success'));
      setIsModalOpen(false);
      setFormData({ name: '', role: '', organization: '', email: '', phone: '' });
      onStakeholderAdded?.();
    } catch {
      showToast('error', t('projects.stakeholder_add_failed'));
    } finally {
      setIsLoading(false);
    }
  };

  const openDeleteStakeholderModal = (stakeholderId: number, stakeholderName: string) => {
    setDeleteModal({ isOpen: true, stakeholderId, stakeholderName });
  };

  const handleConfirmRemoveStakeholder = async () => {
    if (!deleteModal.stakeholderId) return;

    setIsDeleting(true);
    try {
      await projectsApi.removeStakeholder(projectId, deleteModal.stakeholderId);
      showToast('success', t('projects.stakeholder_deleted_success'));
      setDeleteModal({ isOpen: false, stakeholderId: null, stakeholderName: '' });
      onStakeholderRemoved?.();
    } catch {
      showToast('error', t('projects.stakeholder_delete_failed'));
    } finally {
      setIsDeleting(false);
    }
  };

  // فتح نافذة العرض
  const handleViewStakeholder = (stakeholder: Stakeholder) => {
    setSelectedStakeholder(stakeholder);
    setViewEditMode('view');
    setViewEditModalOpen(true);
  };

  // فتح نافذة التعديل
  const handleEditStakeholder = (stakeholder: Stakeholder) => {
    setSelectedStakeholder(stakeholder);
    setViewEditMode('edit');
    setViewEditModalOpen(true);
  };

  // حفظ التعديلات
  const handleSaveStakeholder = async (data: Partial<Stakeholder>) => {
    if (!selectedStakeholder) return;

    setIsUpdating(true);
    try {
      await projectsApi.updateStakeholder(projectId, selectedStakeholder.id, data);
      showToast('success', t('projects.stakeholder_updated_success'));
      setViewEditModalOpen(false);
      setSelectedStakeholder(null);
      onStakeholderUpdated?.();
    } catch {
      showToast('error', t('projects.stakeholder_update_failed'));
    } finally {
      setIsUpdating(false);
    }
  };

  return (
    <div className="space-y-4">
      <SectionHeader
        icon={IconUserCheck}
        iconTone="project"
        iconVariant="subtle"
        level={3}
        title={t('projects.stakeholders')}
        meta={<span className="text-[var(--text-tertiary)]">{stakeholders.length} {t('projects.person_count_unit')}</span>}
        actions={
          <div className="flex items-center gap-2">
            {/* View Toggle */}
            <div className="flex items-center bg-[var(--surface-muted)] rounded-lg p-0">
              <button
                onClick={() => setViewMode('table')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'table' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'}`}
                title={t('projects.view_table')}
                aria-label={t('projects.view_table')}
                aria-pressed={viewMode === 'table'}
              >
                <IconList className="h-4 w-4" />
              </button>
              <button
                onClick={() => setViewMode('cards')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'cards' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'}`}
                title={t('projects.view_cards')}
                aria-label={t('projects.view_cards')}
                aria-pressed={viewMode === 'cards'}
              >
                <IconLayoutGrid className="h-4 w-4" />
              </button>
            </div>
            {canEdit && (
              <Button variant="outline" size="sm" leftIcon={<IconUserPlus className="h-4 w-4" />} onClick={() => setIsModalOpen(true)}>
                {t('projects.add_stakeholder')}
              </Button>
            )}
          </div>
        }
      />

      {/* Stakeholders Content */}
      {stakeholders.length === 0 ? (
        <Card>
          <EmptyState
            icon={IconUserCheck}
            title={t('projects.no_stakeholders')}
            description={t('projects.add_project_stakeholders')}
            size="lg"
            action={canEdit ? (
              <Button leftIcon={<IconUserPlus className="h-4 w-4" />} onClick={() => setIsModalOpen(true)}>
                {t('projects.add_stakeholder')}
              </Button>
            ) : undefined}
          />
        </Card>
      ) : viewMode === 'table' ? (
        /* Table View */
        <Card className="border border-[var(--border-default)]">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('projects.role')}</TableHead>
                  <TableHead>{t('projects.organization')}</TableHead>
                  <TableHead className="w-16">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {stakeholders.map((stakeholder) => (
                  <TableRow key={stakeholder.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <div className="h-8 w-8 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center shrink-0">
                          <span className="text-[var(--accent-default)] font-bold text-sm">
                            {stakeholder.name.charAt(0)}
                          </span>
                        </div>
                        <span className="font-medium text-[var(--text-primary)]">{stakeholder.name}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <span className="text-[var(--text-secondary)]">{stakeholderRoleLabels[stakeholder.role] || stakeholder.role}</span>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <IconBuilding className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
                        <span className="text-[var(--text-secondary)]">{stakeholder.organization || t('projects.not_specified')}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <button
                          onClick={() => handleViewStakeholder(stakeholder)}
                          className="p-1 rounded-lg hover:bg-[var(--accent-subtle)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                          title={t('common.view')}
                          aria-label={t('common.view')}
                        >
                          <IconEye className="h-4 w-4" />
                        </button>
                        {canEdit && (
                          <>
                            <button
                              onClick={() => handleEditStakeholder(stakeholder)}
                              className="p-1 rounded-lg hover:bg-[var(--accent-subtle)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                              title={t('common.edit')}
                              aria-label={t('common.edit')}
                            >
                              <IconPencil className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => openDeleteStakeholderModal(stakeholder.id, stakeholder.name)}
                              className="p-1 rounded-lg hover:bg-[var(--status-danger-subtle)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                              title={t('common.delete')}
                              aria-label={t('common.delete')}
                            >
                              <IconTrash className="h-4 w-4" />
                            </button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </Card>
      ) : (
        /* Cards View */
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {stakeholders.map((stakeholder) => (
            <Card key={stakeholder.id} className="hover:shadow-md transition-shadow border border-[var(--border-default)]">
              <CardContent className="p-4">
                <div className="flex items-start gap-3">
                  <div className="h-10 w-10 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center shrink-0">
                    <span className="text-[var(--accent-default)] font-bold">
                      {stakeholder.name.charAt(0)}
                    </span>
                  </div>
                  <div className="flex-1 min-w-0">
                    <h4 className="font-semibold text-[var(--text-primary)] truncate">{stakeholder.name}</h4>
                    <p className="text-sm text-[var(--text-secondary)] truncate">{stakeholderRoleLabels[stakeholder.role] || stakeholder.role}</p>
                    <div className="flex items-center gap-1 mt-2">
                      <IconBuilding className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
                      <span className="text-xs text-[var(--text-secondary)]">{stakeholder.organization || t('projects.not_specified')}</span>
                    </div>
                  </div>
                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => handleViewStakeholder(stakeholder)}
                      className="p-1 rounded-lg hover:bg-[var(--accent-subtle)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                      title={t('common.view')}
                      aria-label={t('common.view')}
                    >
                      <IconEye className="h-4 w-4" />
                    </button>
                    {canEdit && (
                      <>
                        <button
                          onClick={() => handleEditStakeholder(stakeholder)}
                          className="p-1 rounded-lg hover:bg-[var(--accent-subtle)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                          title={t('common.edit')}
                          aria-label={t('common.edit')}
                        >
                          <IconPencil className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openDeleteStakeholderModal(stakeholder.id, stakeholder.name)}
                          className="p-1 rounded-lg hover:bg-[var(--status-danger-subtle)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                          title={t('common.delete')}
                          aria-label={t('common.delete')}
                        >
                          <IconTrash className="h-4 w-4" />
                        </button>
                      </>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Add Stakeholder Modal - New Design */}
      {isModalOpen && (
        <AddStakeholderModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          formData={formData}
          onFormChange={setFormData}
          onAdd={handleAddStakeholder}
          isLoading={isLoading}
        />
      )}

      {/* View/Edit Stakeholder Modal */}
      <ViewEditStakeholderModal
        isOpen={viewEditModalOpen}
        onClose={() => {
          setViewEditModalOpen(false);
          setSelectedStakeholder(null);
        }}
        stakeholder={selectedStakeholder}
        mode={viewEditMode}
        onSave={handleSaveStakeholder}
        isLoading={isUpdating}
      />

      {/* Delete Stakeholder Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.stakeholderId !== null ? { id: deleteModal.stakeholderId, name: deleteModal.stakeholderName } : null}
        onClose={() => setDeleteModal({ isOpen: false, stakeholderId: null, stakeholderName: '' })}
        onConfirm={handleConfirmRemoveStakeholder}
        title={t('projects.stakeholder_delete_confirm')}
        itemName={deleteModal.stakeholderName}
        itemSubtitle={t('common.name')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default StakeholdersSection;
