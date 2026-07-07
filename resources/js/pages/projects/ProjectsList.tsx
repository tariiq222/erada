import React, { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { projectsApi } from '@entities/project';
import { programsApi } from '@entities/strategy';
import {
  Button,
  PageHeader,
  Tabs,
  TabsList,
  TabsTrigger,
  IconPlus,
  IconFilter,
  IconLayoutKanban,
  IconChartBar,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { useAuth } from '@shared/contexts/AuthContext';
import { useCan } from '@shared/api/access';
import {
  type Project,
  type PaginatedResponse,
  type ProgramOption,
  FiltersCard,
  ProjectsTable,
  DeleteProjectModal,
} from './list';
import { TriageModal } from './triage/TriageModal';
import type { TriageProjectType, TriageAnswers } from './triage/TriageModal';

const ProjectsList: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [showTriage, setShowTriage] = useState(false);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  // Read ?type= / ?mine= from URL — drives the active tab and query
  const typeFilter = searchParams.get('type') || '';
  const mineFilter = searchParams.get('mine') === '1';
  const activeTab = mineFilter
    ? 'mine'
    : typeFilter === 'development'
      ? 'development'
      : typeFilter === 'improvement'
        ? 'improvement'
        : 'all';

  const [filters, setFilters] = useState({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
    priority: searchParams.get('priority') || '',
    program_id: searchParams.get('program_id') || '',
  });
  const [programs, setPrograms] = useState<ProgramOption[]>([]);

  const [showFilters, setShowFilters] = useState(false);
  const [deleteModal, setDeleteModal] = useState<{ isOpen: boolean; project: Project | null }>({
    isOpen: false,
    project: null,
  });
  const [isDeleting, setIsDeleting] = useState(false);

  const { showToast } = useToast();
  const { hasPermission } = useAuth();
  // Phase F4.1 — access-first for module-level actions. `useCan` reads
  // `user.access`; the `hasPermission` half is the stale-session fallback
  // kept alive until the Phase 9 cleanup freeze completes.
  const canCreateProject =
    useCan('projects.create') || hasPermission('projects.create');
  // Phase 9.3 freeze cleanup — Delete is gated on the canonical
  // `projects.delete` capability (engine-enforced). Per-record `abilities.delete`
  // is the source of truth for the row; super_admin is NOT the gate. We keep
  // the stale `hasPermission` fallback for mid-deploy sessions still carrying
  // the legacy key.
  const canDeleteProject =
    useCan('projects.delete') || hasPermission('projects.delete');

  // Open triage modal when navigated from the sidebar "create" link (?intake=1)
  useEffect(() => {
    if (searchParams.get('intake')) {
      setShowTriage(true);
      const next = new URLSearchParams(searchParams);
      next.delete('intake');
      setSearchParams(next, { replace: true });
    }
  }, [searchParams, setSearchParams]);

  const fetchPrograms = async () => {
    try {
      const response = (await programsApi.getList()) as ProgramOption[];
      setPrograms(response || []);
    } catch (error) {
      console.error('Failed to fetch programs:', error);
    }
  };

  const fetchProjects = async (page = 1) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(page) };
      if (filters.search) params.search = filters.search;
      if (filters.status) params.status = filters.status;
      if (filters.priority) params.priority = filters.priority;
      if (filters.program_id) params.program_id = filters.program_id;
      if (typeFilter) params.type = typeFilter;
      if (mineFilter) params.mine = '1';

      const response = (await projectsApi.getAll(params)) as PaginatedResponse;
      setProjects(response.data);
      setPagination({
        currentPage: response.current_page,
        lastPage: response.last_page,
        total: response.total,
      });
    } catch (error) {
      console.error('Failed to fetch projects:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProjects();
    fetchPrograms();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [typeFilter, mineFilter]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const params = new URLSearchParams();
    if (mineFilter) params.set('mine', '1');
    if (typeFilter) params.set('type', typeFilter);
    if (filters.search) params.set('search', filters.search);
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.program_id) params.set('program_id', filters.program_id);
    setSearchParams(params);
    fetchProjects(1);
  };

  const handleTabChange = (tab: string) => {
    const params = new URLSearchParams();
    if (tab === 'mine') params.set('mine', '1');
    else if (tab === 'development') params.set('type', 'development');
    else if (tab === 'improvement') params.set('type', 'improvement');
    if (filters.search) params.set('search', filters.search);
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.program_id) params.set('program_id', filters.program_id);
    setSearchParams(params);
  };

  const handlePageChange = (page: number) => {
    fetchProjects(page);
  };

  const handleTriageComplete = (type: TriageProjectType, triageAnswers: TriageAnswers) => {
    setShowTriage(false);
    // Carry the triage answers into the create form via navigation state so the
    // form can submit them as triage_answers (backend accepts it).
    navigate(`/projects/create?type=${type}`, { state: { triageAnswers } });
  };

  const handleDeleteProject = async () => {
    if (!deleteModal.project) return;

    setIsDeleting(true);
    try {
      await projectsApi.delete(deleteModal.project.id);
      showToast('success', t('projects.delete_success', { name: deleteModal.project.name }));
      setDeleteModal({ isOpen: false, project: null });
      fetchProjects(pagination.currentPage);
    } catch (error: any) {
      showToast('error', error.message || t('projects.delete_failed'));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('projects.title')}
        description={t('projects.manage_and_track')}
        icon={IconLayoutKanban}
        iconTone="project"
        actions={
          <>
            <Button
              variant="outline"
              size="sm"
              leftIcon={<IconChartBar className="h-4 w-4" />}
              onClick={() => navigate('/projects/statistics')}
            >
              {t('nav.statistics')}
            </Button>
            <Button
              variant={showFilters ? 'secondary' : 'outline'}
              size="sm"
              leftIcon={<IconFilter className="h-4 w-4" />}
              onClick={() => setShowFilters(!showFilters)}
              aria-expanded={showFilters}
              aria-controls={showFilters ? 'projects-filters' : undefined}
            >
              {t('common.filter')}
            </Button>
            {canCreateProject && (
              <Button
                leftIcon={<IconPlus className="h-4 w-4" />}
                size="sm"
                onClick={() => setShowTriage(true)}
              >
                {t('projects.new_request')}
              </Button>
            )}
          </>
        }
      />

      {/* Tabs: مشاريعي / الكل / تطويرية / تحسين */}
      <Tabs value={activeTab} onValueChange={handleTabChange} defaultValue={activeTab}>
        <TabsList>
          <TabsTrigger value="mine">{t('nav.my_projects')}</TabsTrigger>
          <TabsTrigger value="all">{t('common.all')}</TabsTrigger>
          <TabsTrigger value="development">{t('nav.new_projects')}</TabsTrigger>
          <TabsTrigger value="improvement">{t('nav.improvement_projects')}</TabsTrigger>
        </TabsList>
      </Tabs>

      {/* Filters */}
      {showFilters && (
        <div id="projects-filters">
          <FiltersCard
            filters={filters}
            onFiltersChange={setFilters}
            onSearch={handleSearch}
            onClose={() => setShowFilters(false)}
            programs={programs}
          />
        </div>
      )}

      {/* Table */}
      <ProjectsTable
        projects={projects}
        loading={loading}
        pagination={pagination}
        canDeleteProject={canDeleteProject}
        onPageChange={handlePageChange}
        onDelete={(project) => setDeleteModal({ isOpen: true, project })}
        onCreate={canCreateProject ? () => setShowTriage(true) : undefined}
      />

      {/* Delete Confirmation Modal */}
      <DeleteProjectModal
        isOpen={deleteModal.isOpen}
        project={deleteModal.project}
        isDeleting={isDeleting}
        onClose={() => setDeleteModal({ isOpen: false, project: null })}
        onConfirm={handleDeleteProject}
      />

      {/* Triage Modal – classifies project type before creation */}
      <TriageModal
        open={showTriage}
        onClose={() => setShowTriage(false)}
        onComplete={handleTriageComplete}
      />
    </div>
  );
};

export default ProjectsList;
