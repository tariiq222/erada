import { useEffect, useState, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { projectsApi } from '@entities/project';
import { programsApi } from '@entities/strategy';
import { useToast } from '@shared/ui/Toast';
import type { Program, Project, UnlinkedProject } from './types';

interface UseProgramViewOptions {
  id: string | undefined;
}

export function useProgramView({ id }: UseProgramViewOptions) {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { showToast } = useToast();

  const [program, setProgram] = useState<Program | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [unlinkedProjects, setUnlinkedProjects] = useState<UnlinkedProject[]>([]);
  const [loading, setLoading] = useState(true);
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [linkingProjectId, setLinkingProjectId] = useState<number | null>(null);

  const defaultTab = searchParams.get('tab') || 'overview';

  const fetchProgram = useCallback(async () => {
    try {
      const data = (await programsApi.getOne(Number(id))) as Program;
      setProgram(data);
    } catch {
      showToast('error', 'فشل في تحميل بيانات المبادرة');
      navigate('/strategy/programs');
    }
  }, [id, navigate, showToast]);

  const fetchProjects = useCallback(async () => {
    try {
      const params = { program_id: String(id) };
      const response = (await projectsApi.getAll(params)) as { data: Project[] };
      setProjects(response.data);
    } catch (error) {
      console.error('Failed to fetch projects:', error);
    }
  }, [id]);

  const fetchUnlinkedProjects = async () => {
    try {
      const response = (await programsApi.getUnlinkedProjects()) as UnlinkedProject[];
      setUnlinkedProjects(response || []);
    } catch (error) {
      console.error('Failed to fetch unlinked projects:', error);
    }
  };

  useEffect(() => {
    const loadData = async () => {
      setLoading(true);
      await Promise.all([fetchProgram(), fetchProjects()]);
      setLoading(false);
    };
    loadData();
  }, [fetchProgram, fetchProjects]);

  const handleLinkProject = async (projectId: number) => {
    setLinkingProjectId(projectId);
    try {
      await programsApi.linkProject(Number(id), projectId);
      showToast('success', 'تم ربط المشروع بالمبادرة بنجاح');
      await Promise.all([fetchProgram(), fetchProjects(), fetchUnlinkedProjects()]);
      setShowLinkModal(false);
    } catch (error: unknown) {
      const err = error as { message?: string };
      showToast('error', err.message || 'فشل في ربط المشروع');
    } finally {
      setLinkingProjectId(null);
    }
  };

  const handleUnlinkProject = async (projectId: number) => {
    if (!confirm('هل أنت متأكد من فك ربط هذا المشروع من المبادرة؟')) return;

    try {
      await programsApi.unlinkProject(Number(id), projectId);
      showToast('success', 'تم فك ربط المشروع بنجاح');
      await Promise.all([fetchProgram(), fetchProjects()]);
    } catch (error: unknown) {
      const err = error as { message?: string };
      showToast('error', err.message || 'فشل في فك ربط المشروع');
    }
  };

  const openLinkModal = async () => {
    await fetchUnlinkedProjects();
    setShowLinkModal(true);
  };

  // حساب إحصائيات المشاريع
  const completedProjects = projects.filter((p) => p.status === 'completed').length;
  const inProgressProjects = projects.filter((p) => p.status === 'in_progress').length;
  const avgProgress = projects.length > 0 ? Math.round(projects.reduce((sum, p) => sum + p.progress, 0) / projects.length) : 0;

  return {
    // State
    program,
    projects,
    unlinkedProjects,
    loading,
    showLinkModal,
    linkingProjectId,
    defaultTab,

    // Computed
    completedProjects,
    inProgressProjects,
    avgProgress,

    // Handlers
    setShowLinkModal,
    handleLinkProject,
    handleUnlinkProject,
    openLinkModal,
  };
}
