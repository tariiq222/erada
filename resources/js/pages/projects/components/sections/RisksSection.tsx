import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconAlertTriangle, IconPlus, IconList, IconLayoutGrid, IconTrash, IconPencil, IconRefresh} from '@tabler/icons-react';
import { projectsApi } from '@entities/project';
import { useToast } from '@shared/ui/Toast';
import {
  Card,
  CardContent,
  Button,
  Badge,
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
import { AddRiskModal, RiskStatusModal } from '../modals';
import type { ProjectDetails } from '../../types';

interface Risk {
  id: number;
  risk: string;
  probability: string;
  impact: string;
  response?: string;
  status: string;
}

interface RisksSectionProps {
  risks: ProjectDetails['risks'];
  projectId: number;
  onRiskAdded?: () => void;
  onRiskRemoved?: () => void;
  canEdit?: boolean;
}

const RisksSection: React.FC<RisksSectionProps> = ({ risks, projectId, onRiskAdded, onRiskRemoved, canEdit = true }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isStatusModalOpen, setIsStatusModalOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [isStatusLoading, setIsStatusLoading] = useState(false);
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');
  const [modalMode, setModalMode] = useState<'add' | 'edit'>('add');
  const [editingRiskId, setEditingRiskId] = useState<number | null>(null);
  const [statusChangeRisk, setStatusChangeRisk] = useState<Risk | null>(null);
  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    riskId: number | null;
    riskName: string;
  }>({ isOpen: false, riskId: null, riskName: '' });
  const [isDeleting, setIsDeleting] = useState(false);
  const [formData, setFormData] = useState({
    risk: '',
    probability: '',
    impact: '',
    response: '',
    status: 'open',
  });

  const resetForm = () => {
    setFormData({ risk: '', probability: '', impact: '', response: '', status: 'open' });
    setEditingRiskId(null);
    setModalMode('add');
  };

  const openAddModal = () => {
    resetForm();
    setIsModalOpen(true);
  };

  const openEditModal = (risk: Risk) => {
    setFormData({
      risk: risk.risk,
      probability: risk.probability,
      impact: risk.impact,
      response: risk.response || '',
      status: risk.status,
    });
    setEditingRiskId(risk.id);
    setModalMode('edit');
    setIsModalOpen(true);
  };

  const openStatusModal = (risk: Risk) => {
    setStatusChangeRisk(risk);
    setIsStatusModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    resetForm();
  };

  const handleCloseStatusModal = () => {
    setIsStatusModalOpen(false);
    setStatusChangeRisk(null);
  };

  const handleAddRisk = async () => {
    if (!formData.risk || !formData.probability || !formData.impact) {
      showToast('error', t('projects.risk_fields_required'));
      return;
    }

    setIsLoading(true);
    try {
      await projectsApi.addRisk(projectId, formData);
      showToast('success', t('projects.risk_added_success'));
      handleCloseModal();
      if (onRiskAdded) {
        await onRiskAdded();
      }
    } catch {
      showToast('error', t('projects.risk_add_failed'));
    } finally {
      setIsLoading(false);
    }
  };

  const handleUpdateRisk = async () => {
    if (!formData.risk || !formData.probability || !formData.impact || !editingRiskId) {
      showToast('error', t('projects.risk_fields_required'));
      return;
    }

    setIsLoading(true);
    try {
      // إرسال التعديلات بدون الحالة (الحالة تُغيّر من نافذة منفصلة)
      const { status, ...dataWithoutStatus } = formData;
      await projectsApi.updateRisk(projectId, editingRiskId, dataWithoutStatus);
      showToast('success', t('projects.risk_updated_success'));
      handleCloseModal();
      if (onRiskAdded) {
        await onRiskAdded();
      }
    } catch {
      showToast('error', t('projects.risk_update_failed'));
    } finally {
      setIsLoading(false);
    }
  };

  const handleStatusChange = async (newStatus: string, actionTaken: string) => {
    if (!statusChangeRisk) return;

    setIsStatusLoading(true);
    try {
      // تحديث الحالة مع الإجراء المتخذ (يُضاف لخطة الاستجابة)
      const updateData: { status: string; response?: string } = { status: newStatus };

      // إذا كان هناك إجراء متخذ، نضيفه لخطة الاستجابة
      if (actionTaken.trim()) {
        const timestamp = new Date().toLocaleDateString('ar-EG-u-nu-latn');
        const newResponse = statusChangeRisk.response
          ? `${statusChangeRisk.response}\n\n[${timestamp}] ${actionTaken}`
          : `[${timestamp}] ${actionTaken}`;
        updateData.response = newResponse;
      }

      await projectsApi.updateRisk(projectId, statusChangeRisk.id, updateData);
      showToast('success', t('projects.risk_status_updated'));
      handleCloseStatusModal();
      if (onRiskAdded) {
        await onRiskAdded();
      }
    } catch {
      showToast('error', t('projects.risk_status_update_failed'));
    } finally {
      setIsStatusLoading(false);
    }
  };

  const handleSubmit = () => {
    if (modalMode === 'edit') {
      handleUpdateRisk();
    } else {
      handleAddRisk();
    }
  };

  const openDeleteRiskModal = (riskId: number, riskName: string) => {
    setDeleteModal({ isOpen: true, riskId, riskName });
  };

  const handleConfirmRemoveRisk = async () => {
    if (!deleteModal.riskId) return;

    setIsDeleting(true);
    try {
      await projectsApi.removeRisk(projectId, deleteModal.riskId);
      showToast('success', t('projects.risk_deleted_success'));
      setDeleteModal({ isOpen: false, riskId: null, riskName: '' });
      onRiskRemoved?.();
    } catch {
      showToast('error', t('projects.risk_delete_failed'));
    } finally {
      setIsDeleting(false);
    }
  };

  const getProbabilityBadge = (probability: string) => {
    switch (probability) {
      case 'high':
        return <Badge variant="danger">{t('priority.high')}</Badge>;
      case 'medium':
        return <Badge variant="warning">{t('priority.medium')}</Badge>;
      default:
        return <Badge variant="default">{t('priority.low')}</Badge>;
    }
  };

  const getImpactBadge = (impact: string) => {
    switch (impact) {
      case 'high':
        return <Badge variant="danger">{t('projects.impact_high')}</Badge>;
      case 'medium':
        return <Badge variant="warning">{t('projects.impact_medium')}</Badge>;
      default:
        return <Badge variant="default">{t('projects.impact_low')}</Badge>;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'closed':
        return <Badge variant="success">{t('status.closed')}</Badge>;
      case 'mitigated':
        return <Badge variant="accent">{t('status.mitigated')}</Badge>;
      default: // 'open'
        return <Badge variant="warning">{t('status.open')}</Badge>;
    }
  };

  const getRiskLevel = (probability: string, impact: string) => {
    const levels: Record<string, Record<string, string>> = {
      high: { high: 'critical', medium: 'high', low: 'medium' },
      medium: { high: 'high', medium: 'medium', low: 'low' },
      low: { high: 'medium', medium: 'low', low: 'low' },
    };
    return levels[probability]?.[impact] || 'low';
  };

  const getRiskLevelColor = (level: string) => {
    switch (level) {
      case 'critical':
        return 'border-[var(--status-danger)] bg-[var(--status-danger-subtle)]';
      case 'high':
        return 'border-[var(--status-warning)] bg-[var(--status-warning-subtle)]';
      case 'medium':
        return 'border-[var(--status-warning)] bg-[var(--status-warning-subtle)]';
      default:
        return 'border-[var(--status-success)] bg-[var(--status-success-subtle)]';
    }
  };

  return (
    <div className="space-y-4">
      <SectionHeader
        icon={IconAlertTriangle}
        iconTone="risk"
        iconVariant="subtle"
        level={3}
        title={t('projects.risk_register')}
        meta={<span className="text-[var(--text-tertiary)]">{risks.length} {t('projects.risk_count_unit')}</span>}
        actions={
          <div className="flex items-center gap-2">
            {/* View Toggle */}
            <div className="flex items-center bg-[var(--surface-muted)] rounded-lg p-0">
              <button
                onClick={() => setViewMode('table')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'table' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('projects.view_table')}
                aria-label={t('projects.view_table')}
                aria-pressed={viewMode === 'table'}
              >
                <IconList className="h-4 w-4" />
              </button>
              <button
                onClick={() => setViewMode('cards')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'cards' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('projects.view_cards')}
                aria-label={t('projects.view_cards')}
                aria-pressed={viewMode === 'cards'}
              >
                <IconLayoutGrid className="h-4 w-4" />
              </button>
            </div>
            {canEdit && (
              <Button variant="outline" size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={openAddModal}>
                {t('projects.add_risk')}
              </Button>
            )}
          </div>
        }
      />

      {/* Risks Content */}
      {risks.length === 0 ? (
        <Card>
          <EmptyState
            icon={IconAlertTriangle}
            title={t('projects.no_risks')}
            description={t('projects.add_potential_risks')}
            size="lg"
            action={canEdit ? (
              <Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={openAddModal}>
                {t('projects.add_risk')}
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
                  <TableHead>{t('projects.risk_label')}</TableHead>
                  <TableHead>{t('projects.probability')}</TableHead>
                  <TableHead>{t('projects.impact')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="w-28">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {risks.map((risk) => (
                  <TableRow key={risk.id}>
                    <TableCell>
                      <span className="font-medium text-[var(--text-primary)] line-clamp-2">{risk.risk}</span>
                    </TableCell>
                    <TableCell>
                      {getProbabilityBadge(risk.probability)}
                    </TableCell>
                    <TableCell>
                      {getImpactBadge(risk.impact)}
                    </TableCell>
                    <TableCell>
                      {getStatusBadge(risk.status)}
                    </TableCell>
                    <TableCell>
                      {canEdit && (
                        <div className="flex items-center gap-1">
                          <button
                            onClick={() => openEditModal(risk)}
                            className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                            title={t('common.edit')}
                            aria-label={t('common.edit')}
                          >
                            <IconPencil className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => openStatusModal(risk)}
                            className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-warning-text)] transition-colors"
                            title={t('projects.change_status')}
                            aria-label={t('projects.change_status')}
                          >
                            <IconRefresh className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => openDeleteRiskModal(risk.id, risk.risk)}
                            className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-danger-text)] transition-colors"
                            title={t('common.delete')}
                            aria-label={t('common.delete')}
                          >
                            <IconTrash className="h-4 w-4" />
                          </button>
                        </div>
                      )}
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
          {risks.map((risk) => {
            const riskLevel = getRiskLevel(risk.probability, risk.impact);
            const levelColor = getRiskLevelColor(riskLevel);

            return (
              <Card key={risk.id} className={`hover:shadow-md transition-shadow border ${levelColor}`}>
                <CardContent className="p-4 space-y-3">
                  {/* Header */}
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <h4 className="font-semibold text-[var(--text-primary)] line-clamp-2">{risk.risk}</h4>
                    </div>
                    {canEdit && (
                      <div className="flex items-center gap-1 shrink-0 ms-2">
                        <button
                          onClick={() => openEditModal(risk)}
                          className="p-1 rounded-lg hover:bg-[var(--surface-base)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)] transition-colors"
                          title={t('common.edit')}
                          aria-label={t('common.edit')}
                        >
                          <IconPencil className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openStatusModal(risk)}
                          className="p-1 rounded-lg hover:bg-[var(--surface-base)] text-[var(--text-tertiary)] hover:text-[var(--status-warning-text)] transition-colors"
                          title={t('projects.change_status')}
                          aria-label={t('projects.change_status')}
                        >
                          <IconRefresh className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openDeleteRiskModal(risk.id, risk.risk)}
                          className="p-1 rounded-lg hover:bg-[var(--surface-base)] text-[var(--text-tertiary)] hover:text-[var(--status-danger-text)] transition-colors"
                          title={t('common.delete')}
                          aria-label={t('common.delete')}
                        >
                          <IconTrash className="h-4 w-4" />
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Probability & Impact */}
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-[var(--text-tertiary)]">{t('projects.probability')}:</span>
                      {getProbabilityBadge(risk.probability)}
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-[var(--text-tertiary)]">{t('projects.impact')}:</span>
                      {getImpactBadge(risk.impact)}
                    </div>
                  </div>

                  {/* Status */}
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-[var(--text-tertiary)]">{t('common.status')}:</span>
                    {getStatusBadge(risk.status)}
                  </div>

                  {/* Response if exists */}
                  {risk.response && (
                    <div className="pt-2 border-t border-[var(--border-default)]">
                      <p className="text-xs text-[var(--text-tertiary)] mb-1">{t('projects.response_plan')}:</p>
                      <p className="text-sm text-[var(--text-secondary)] line-clamp-2">{risk.response}</p>
                    </div>
                  )}
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Add/Edit Risk Modal */}
      {isModalOpen && (
        <AddRiskModal
          isOpen={isModalOpen}
          onClose={handleCloseModal}
          formData={formData}
          onFormChange={setFormData}
          onAdd={handleSubmit}
          isLoading={isLoading}
          mode={modalMode}
        />
      )}

      {/* Status Change Modal */}
      {isStatusModalOpen && statusChangeRisk && (
        <RiskStatusModal
          isOpen={isStatusModalOpen}
          onClose={handleCloseStatusModal}
          currentStatus={statusChangeRisk.status}
          currentResponse={statusChangeRisk.response}
          onSave={handleStatusChange}
          isLoading={isStatusLoading}
        />
      )}

      {/* Delete Risk Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.riskId !== null ? { id: deleteModal.riskId, name: deleteModal.riskName } : null}
        onClose={() => setDeleteModal({ isOpen: false, riskId: null, riskName: '' })}
        onConfirm={handleConfirmRemoveRisk}
        title={t('projects.risk_delete_confirm')}
        itemName={deleteModal.riskName}
        itemSubtitle={t('projects.risk_label')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default RisksSection;
