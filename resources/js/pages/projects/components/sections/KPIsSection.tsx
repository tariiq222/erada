import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconTrendingUp, IconPlus, IconList, IconLayoutGrid, IconTarget, IconTrash} from '@tabler/icons-react';
import { performanceApi } from '@entities/performance';
import type { PerformanceKPI } from '@entities/performance';
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
import { AddKPIModal } from '../modals';
import type { ProjectDetails, ProjectKPI } from '../../types';

interface KPIsSectionProps {
  kpis: ProjectDetails['kpis'];
  projectId: number;
  organizationId?: number | null;
  onKPIAdded?: () => void;
  onKPIRemoved?: () => void;
  onKPICountChange?: (count: number) => void;
  canEdit?: boolean;
}

const normalizePerformanceKPI = (kpi: PerformanceKPI, projectId: number): ProjectKPI => {
  const isProjectLinkType = (linkableType?: string) => {
    if (!linkableType) return true;

    const normalizedType = linkableType.toLowerCase().replace(/\\/g, '/');
    return normalizedType === 'project'
      || normalizedType.endsWith('/project')
      || normalizedType.includes('/projects/');
  };
  const matchesCurrentProject = (item: NonNullable<PerformanceKPI['links']>[number]) => (
    Number(item.linkable_id) === projectId && isProjectLinkType(item.linkable_type)
  );
  const link = kpi.links?.find((item) => matchesCurrentProject(item) && item.relationship_type === 'primary')
    ?? kpi.links?.find(matchesCurrentProject);

  return {
    id: kpi.id,
    indicator: kpi.name,
    target: String(kpi.target ?? ''),
    current_value: String(kpi.current_value ?? ''),
    unit: kpi.unit ?? null,
    performance_link_id: link?.id ?? null,
    achievement_percentage: kpi.achievement_percentage ?? null,
    performance_status: kpi.performance_status ?? null,
  };
};

const KPIsSection: React.FC<KPIsSectionProps> = ({
  kpis,
  projectId,
  organizationId,
  onKPIAdded,
  onKPIRemoved,
  onKPICountChange,
  canEdit = true,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');
  const [performanceKpis, setPerformanceKpis] = useState<ProjectKPI[] | null>(null);
  const [formData, setFormData] = useState({
    indicator: '',
    target: '',
    current_value: '',
    unit: '',
  });
  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    kpi: ProjectKPI | null;
  }>({ isOpen: false, kpi: null });
  const [isDeleting, setIsDeleting] = useState(false);
  const displayedKpis = performanceKpis ?? kpis;

  useEffect(() => {
    let isCancelled = false;

    const fetchPerformanceKpis = async () => {
      try {
        const response = await performanceApi.listContextKPIs('project', projectId);
        if (isCancelled) return;

        const normalizedKpis = response.data.map((kpi) => normalizePerformanceKPI(kpi, projectId));
        setPerformanceKpis(normalizedKpis);
        onKPICountChange?.(normalizedKpis.length);
      } catch {
        if (isCancelled) return;

        setPerformanceKpis(null);
        onKPICountChange?.(kpis.length);
      }
    };

    fetchPerformanceKpis();

    return () => {
      isCancelled = true;
    };
  }, [projectId, kpis.length, onKPICountChange]);

  const refreshPerformanceKpis = async () => {
    const response = await performanceApi.listContextKPIs('project', projectId);
    const normalizedKpis = response.data.map((kpi) => normalizePerformanceKPI(kpi, projectId));
    setPerformanceKpis(normalizedKpis);
    onKPICountChange?.(normalizedKpis.length);
  };

  const handleAddKPI = async () => {
    if (!formData.indicator || !formData.target) {
      showToast('error', t('projects.kpi_fields_required'));
      return;
    }

    setIsLoading(true);
    try {
      const payload = {
        name: formData.indicator,
        target: formData.target,
        ...(formData.current_value ? { current_value: formData.current_value } : {}),
        ...(formData.unit ? { unit: formData.unit } : {}),
        ...(organizationId != null ? { organization_id: organizationId } : {}),
      };
      const response = await performanceApi.createKPI(payload);
      const linkResponse = await performanceApi.createLink(response.kpi.id, {
        linkable_type: 'project',
        linkable_id: projectId,
        relationship_type: 'primary',
      });
      try {
        await refreshPerformanceKpis();
      } catch {
        const nextKpis = [
          ...displayedKpis,
          normalizePerformanceKPI({
            ...response.kpi,
            links: [...(response.kpi.links ?? []), linkResponse.link],
          }, projectId),
        ];
        setPerformanceKpis(nextKpis);
        onKPICountChange?.(nextKpis.length);
      }
      showToast('success', t('projects.kpi_added_success'));
      setIsModalOpen(false);
      setFormData({ indicator: '', target: '', current_value: '', unit: '' });
      onKPIAdded?.();
    } catch {
      showToast('error', t('projects.kpi_add_failed'));
    } finally {
      setIsLoading(false);
    }
  };

  const openDeleteKPIModal = (kpi: ProjectKPI) => {
    setDeleteModal({ isOpen: true, kpi });
  };

  const handleConfirmRemoveKPI = async () => {
    const kpi = deleteModal.kpi;
    if (!kpi) return;

    setIsDeleting(true);
    try {
      if (kpi.performance_link_id == null) {
        showToast('error', t('projects.kpi_delete_failed'));
        setDeleteModal({ isOpen: false, kpi: null });
        return;
      }

      await performanceApi.deleteLink(kpi.id, kpi.performance_link_id);
      const nextKpis = displayedKpis.filter((item) => item.id !== kpi.id || item.performance_link_id !== kpi.performance_link_id);
      setPerformanceKpis(nextKpis);
      onKPICountChange?.(nextKpis.length);
      showToast('success', t('projects.kpi_deleted_success'));
      setDeleteModal({ isOpen: false, kpi: null });
      onKPIRemoved?.();
    } catch {
      showToast('error', t('projects.kpi_delete_failed'));
    } finally {
      setIsDeleting(false);
    }
  };

  const getProgress = (current: string, target: string) => {
    const currentNum = parseFloat(current) || 0;
    const targetNum = parseFloat(target) || 1;
    return Math.min(100, Math.round((currentNum / targetNum) * 100));
  };

  const getProgressColor = (progress: number) => {
    if (progress >= 80) return 'bg-[var(--status-success)]';
    if (progress >= 50) return 'bg-[var(--status-warning)]';
    return 'bg-[var(--status-danger)]';
  };

  return (
    <div className="space-y-4">
      <SectionHeader
        icon={IconTrendingUp}
        iconTone="project"
        iconVariant="subtle"
        level={3}
        title={t('projects.kpis_title')}
        meta={<span className="text-[var(--text-tertiary)]">{displayedKpis.length} {t('projects.kpi_count_unit')}</span>}
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
              <Button variant="outline" size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={() => setIsModalOpen(true)}>
                {t('projects.add_kpi')}
              </Button>
            )}
          </div>
        }
      />

      {/* KPIs Content */}
      {displayedKpis.length === 0 ? (
        <Card>
          <EmptyState
            icon={IconTrendingUp}
            title={t('projects.no_kpis')}
            description={t('projects.add_project_kpis')}
            size="lg"
            action={canEdit ? (
              <Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={() => setIsModalOpen(true)}>
                {t('projects.add_kpi')}
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
                  <TableHead>{t('projects.kpi_indicator')}</TableHead>
                  <TableHead>{t('projects.kpi_current')}</TableHead>
                  <TableHead>{t('projects.kpi_target')}</TableHead>
                  <TableHead>{t('common.progress')}</TableHead>
                  <TableHead className="w-16">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {displayedKpis.map((kpi) => {
                  const progress = getProgress(kpi.current_value, kpi.target);
                  const progressColor = getProgressColor(progress);
                  return (
                    <TableRow key={kpi.id}>
                      <TableCell>
                        <span className="font-medium text-[var(--text-primary)]">{kpi.indicator}</span>
                      </TableCell>
                      <TableCell>
                        <span className="text-[var(--text-secondary)]">{kpi.current_value || '0'}</span>
                      </TableCell>
                      <TableCell>
                        <span className="text-[var(--text-secondary)]">{kpi.target}</span>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <div className="w-20 h-2 bg-[var(--surface-muted)] rounded-full overflow-hidden">
                            <div
                              className={`h-full rounded-full transition-[width] ${progressColor}`}
                              style={{ width: `${progress}%` }}
                            />
                          </div>
                          <span className="text-sm text-[var(--text-tertiary)]">{progress}%</span>
                        </div>
                      </TableCell>
                      <TableCell>
                        {canEdit && (
                          <button
                            onClick={() => openDeleteKPIModal(kpi)}
                            className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                            title={t('common.delete')}
                            aria-label={t('common.delete')}
                          >
                            <IconTrash className="h-4 w-4" />
                          </button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        </Card>
      ) : (
        /* Cards View */
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {displayedKpis.map((kpi) => {
            const progress = getProgress(kpi.current_value, kpi.target);
            const progressColor = getProgressColor(progress);

            return (
              <Card key={kpi.id} className="hover:shadow-md transition-shadow border border-[var(--border-default)]">
                <CardContent className="p-4 space-y-3">
                  {/* Header */}
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <h4 className="font-semibold text-[var(--text-primary)] truncate">{kpi.indicator}</h4>
                      <p className="text-sm text-[var(--text-tertiary)]">{progress}% {t('projects.of_target')}</p>
                    </div>
                    {canEdit && (
                      <button
                        onClick={() => openDeleteKPIModal(kpi)}
                        className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                        title={t('common.delete')}
                        aria-label={t('common.delete')}
                      >
                        <IconTrash className="h-4 w-4" />
                      </button>
                    )}
                  </div>

                  {/* Progress Bar */}
                  <div>
                    <div className="h-2 bg-[var(--surface-muted)] rounded-full overflow-hidden">
                      <div
                        className={`h-full rounded-full transition-[width] ${progressColor}`}
                        style={{ width: `${progress}%` }}
                      />
                    </div>
                  </div>

                  {/* Values */}
                  <div className="flex items-center justify-between">
                    <div className="text-center">
                      <p className="text-xs text-[var(--text-tertiary)]">{t('projects.kpi_current')}</p>
                      <p className="font-bold text-[var(--text-primary)]">{kpi.current_value || '0'}</p>
                    </div>
                    <div className="text-center">
                      <IconTarget className="h-4 w-4 text-[var(--text-tertiary)] mx-auto" />
                    </div>
                    <div className="text-center">
                      <p className="text-xs text-[var(--text-tertiary)]">{t('projects.kpi_target')}</p>
                      <p className="font-bold text-[var(--text-primary)]">{kpi.target}</p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Add KPI Modal */}
      {isModalOpen && (
        <AddKPIModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          formData={formData}
          onFormChange={setFormData}
          onAdd={handleAddKPI}
          isLoading={isLoading}
        />
      )}

      {/* Delete KPI Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.kpi ? { id: deleteModal.kpi.id, name: deleteModal.kpi.indicator } : null}
        onClose={() => setDeleteModal({ isOpen: false, kpi: null })}
        onConfirm={handleConfirmRemoveKPI}
        title={t('projects.kpi_delete_confirm')}
        itemName={deleteModal.kpi?.indicator ?? ''}
        itemSubtitle={t('projects.kpi_indicator')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('common.delete')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default KPIsSection;
