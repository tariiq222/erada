import React, { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { tasksApi } from '@entities/task';
import { useAuth } from '@shared/contexts/AuthContext';
import { useToast } from '@shared/ui/Toast';
import TaskViewModal from '@pages/tasks/TaskViewModal';
import CreateTaskModal from '@features/task-create';
import { StatusTransitionModal, ErrorModal, IncompleteSubtasksModal } from '../modals';
import {
  taskStatusLabels,
  STATUS_ORDER,
  getStatusTransitionRules,
} from '../../constants';
import type { ProjectDetails, TaskType, SubTask, TaskStatus } from '../../types';
import {
  KanbanBoard,
  TasksListView,
  TasksFilters,
  TasksHeader,
  TasksEmptyState,
} from './tasks';

interface ProjectTasksSectionProps {
  tasks: ProjectDetails['tasks'];
  projectId: number;
  projectManagerId?: number;
  members: ProjectDetails['members'];
  project: ProjectDetails;
  onTaskCreated: () => void;
}

const ProjectTasksSection: React.FC<ProjectTasksSectionProps> = ({
  tasks: initialTasks,
  projectId,
  projectManagerId,
  members: _members,
  project,
  onTaskCreated,
}) => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { showToast } = useToast();
  const [viewMode, setViewMode] = useState<'kanban' | 'list'>('kanban');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [priorityFilter, setPriorityFilter] = useState<string>('all');
  const [assigneeFilter, setAssigneeFilter] = useState<string>('all');
  const [showFilters, setShowFilters] = useState(false);
  const [tasks, setTasks] = useState(initialTasks);

  // Drag and Drop state
  const [draggedTask, setDraggedTask] = useState<TaskType | null>(null);
  const [dragOverColumn, setDragOverColumn] = useState<string | null>(null);

  // Task View Modal state
  const [taskModalOpen, setTaskModalOpen] = useState(false);
  const [selectedTaskId, setSelectedTaskId] = useState<number | null>(null);

  // Create Task Modal state
  const [createTaskModalOpen, setCreateTaskModalOpen] = useState(false);

  // Modal state
  const [confirmModal, setConfirmModal] = useState<{
    isOpen: boolean;
    task: TaskType | null;
    newStatus: string;
    confirmationMessage: string;
  }>({ isOpen: false, task: null, newStatus: '', confirmationMessage: '' });

  const [errorModal, setErrorModal] = useState<{
    isOpen: boolean;
    message: string;
  }>({ isOpen: false, message: '' });

  // Subtasks accordion state
  const [expandedSubtasks, setExpandedSubtasks] = useState<number | null>(null);
  const [loadingSubtasks, setLoadingSubtasks] = useState<number | null>(null);
  const [subtasksData, setSubtasksData] = useState<Record<number, SubTask[]>>({});
  const [openSubtaskStatusMenu, setOpenSubtaskStatusMenu] = useState<number | null>(null);

  // Modal state for incomplete subtasks warning
  const [subtasksWarningModal, setSubtasksWarningModal] = useState<{
    isOpen: boolean;
    taskTitle: string;
    incompleteSubtasks: SubTask[];
    targetStatus: string;
  }>({ isOpen: false, taskTitle: '', incompleteSubtasks: [], targetStatus: '' });

  // Determine user role for the transition rules engine.
  // Phase 9.3 freeze cleanup (2026-07-06): drop the `isSuperAdmin()` role-string
  // gate. Role strings are NOT a stable authz surface — the engine never enforced
  // them. Use the canonical `task.abilities?.edit` for "can this user move THIS
  // task". The role-string returned here is a UI-only hint for the transition
  // modal copy; `super_admin` is preserved so the wording stays correct for
  // platform admins who genuinely hold the engine capability, but it is sourced
  // from `user.access` rather than the role-string membership check.
  const getUserRole = useCallback(
    (task?: TaskType): 'super_admin' | 'project_manager' | 'member' => {
      if (task?.abilities?.edit && user?.roles?.includes('super_admin')) {
        return 'super_admin';
      }
      if (user?.id === projectManagerId) return 'project_manager';
      return 'member';
    },
    [user, projectManagerId],
  );

  // Sync tasks with props
  useEffect(() => {
    setTasks(initialTasks);
  }, [initialTasks]);

  // إغلاق قائمة حالة المهمة الفرعية عند النقر خارجها
  useEffect(() => {
    const handleClickOutside = () => {
      if (openSubtaskStatusMenu !== null) {
        setOpenSubtaskStatusMenu(null);
      }
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, [openSubtaskStatusMenu]);

  // حساب عدد الفلاتر النشطة
  const activeFiltersCount = [
    statusFilter !== 'all',
    priorityFilter !== 'all',
    assigneeFilter !== 'all',
  ].filter(Boolean).length;

  // تطبيق الفلاتر
  const filteredTasks = tasks.filter(t => {
    if (statusFilter !== 'all' && t.status !== statusFilter) return false;
    if (priorityFilter !== 'all' && t.priority !== priorityFilter) return false;
    if (assigneeFilter !== 'all' && t.assignee?.id !== Number(assigneeFilter)) return false;
    return true;
  });

  // إعادة تعيين الفلاتر
  const resetFilters = () => {
    setStatusFilter('all');
    setPriorityFilter('all');
    setAssigneeFilter('all');
  };

  // جمع قائمة الأعضاء المعينين
  const assignees = [...new Map(tasks.filter(t => t.assignee).map(t => [t.assignee!.id, t.assignee!])).values()];

  // جلب المهام الفرعية
  const fetchSubtasks = async (taskId: number) => {
    if (subtasksData[taskId]) {
      setExpandedSubtasks(expandedSubtasks === taskId ? null : taskId);
      return;
    }

    setLoadingSubtasks(taskId);
    try {
      const response = await tasksApi.getOne(taskId) as { data?: { subtasks?: SubTask[] }; subtasks?: SubTask[] };
      // Laravel JsonResource يرجع البيانات داخل data wrapper
      const taskData = response.data || response;
      setSubtasksData((prev) => ({
        ...prev,
        [taskId]: taskData.subtasks || [],
      }));
      setExpandedSubtasks(taskId);
    } catch (error) {
      console.error('Failed to fetch subtasks:', error);
    } finally {
      setLoadingSubtasks(null);
    }
  };

  const toggleSubtasks = (e: React.MouseEvent, taskId: number) => {
    e.stopPropagation();
    if (expandedSubtasks === taskId) {
      setExpandedSubtasks(null);
    } else {
      fetchSubtasks(taskId);
    }
  };

  // تغيير حالة المهمة الفرعية مباشرة
  const updateSubtaskStatus = async (e: React.MouseEvent, parentId: number, subtaskId: number, newStatus: string) => {
    e.stopPropagation();
    try {
      await tasksApi.update(subtaskId, { status: newStatus });
      setSubtasksData((prev) => ({
        ...prev,
        [parentId]: prev[parentId].map((st) =>
          st.id === subtaskId ? { ...st, status: newStatus } : st
        ),
      }));
      showToast('success', t('projects.subtask_status_updated'));
    } catch (error) {
      console.error('Failed to update subtask status:', error);
      showToast('error', t('projects.subtask_status_update_failed'));
    }
  };

  // Drag and Drop handlers
  const handleDragStart = useCallback((e: React.DragEvent, task: TaskType) => {
    setDraggedTask(task);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', task.id.toString());
    setTimeout(() => {
      (e.target as HTMLElement).style.opacity = '0.5';
    }, 0);
  }, []);

  const handleDragEnd = useCallback((e: React.DragEvent) => {
    setDraggedTask(null);
    setDragOverColumn(null);
    (e.target as HTMLElement).style.opacity = '1';
  }, []);

  const handleDragOver = useCallback((e: React.DragEvent, status: string) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    setDragOverColumn(status);
  }, []);

  const handleDragLeave = useCallback(() => {
    setDragOverColumn(null);
  }, []);

  const handleDrop = useCallback(async (e: React.DragEvent, newStatus: string) => {
    e.preventDefault();
    setDragOverColumn(null);

    if (!draggedTask || draggedTask.status === newStatus) {
      setDraggedTask(null);
      return;
    }

    const userRole = getUserRole(draggedTask);
    const currentStatus = draggedTask.status as TaskStatus;
    const targetStatus = newStatus as TaskStatus;

    if (!STATUS_ORDER.includes(targetStatus)) {
      setDraggedTask(null);
      return;
    }

    const rules = getStatusTransitionRules(userRole, currentStatus, targetStatus);

    if (!rules.allowed) {
      setErrorModal({ isOpen: true, message: rules.confirmationMessage });
      setDraggedTask(null);
      return;
    }

    // التحقق من المهام الفرعية غير المكتملة قبل التأكيد
    if (targetStatus !== 'in_progress' && (draggedTask.subtasks_count ?? 0) > 0) {
      let subtasks = subtasksData[draggedTask.id];
      if (!subtasks) {
        try {
          const response = await tasksApi.getOne(draggedTask.id) as { data?: { subtasks?: SubTask[] }; subtasks?: SubTask[] };
          // Laravel JsonResource يرجع البيانات داخل data wrapper
          const taskData = response.data || response;
          subtasks = taskData.subtasks || [];
          setSubtasksData((prev) => ({
            ...prev,
            [draggedTask.id]: subtasks,
          }));
        } catch (error) {
          console.error('Failed to fetch subtasks:', error);
          subtasks = [];
        }
      }

      const incompleteSubtasks = subtasks.filter(st => st.status !== 'completed');
      if (incompleteSubtasks.length > 0) {
        setSubtasksWarningModal({
          isOpen: true,
          taskTitle: draggedTask.title,
          incompleteSubtasks,
          targetStatus: taskStatusLabels[targetStatus] || targetStatus,
        });
        setDraggedTask(null);
        return;
      }
    }

    if (rules.requiresConfirmation) {
      setConfirmModal({
        isOpen: true,
        task: draggedTask,
        newStatus,
        confirmationMessage: rules.confirmationMessage,
      });
    } else {
      // تحديث مباشر بدون تأكيد
      const updatedTasks = tasks.map((t) =>
        t.id === draggedTask.id ? { ...t, status: newStatus } : t
      );
      setTasks(updatedTasks);

      tasksApi.update(draggedTask.id, { status: newStatus })
        .then(() => {
          showToast('success', t('projects.task_moved_to', { status: taskStatusLabels[newStatus] }), t('projects.updated'));
        })
        .catch((error: any) => {
          console.error('Failed to update task status:', error);
          setTasks(tasks); // Rollback
          const errorMessage = error?.message || error?.response?.data?.message || t('projects.task_status_update_failed');
          showToast('error', errorMessage, t('common.error'));
        });
      setDraggedTask(null);
    }
  }, [draggedTask, getUserRole, subtasksData, tasks, showToast]);

  const handleConfirmStatusChange = useCallback(async (comment: string, completionData?: { challenges?: string; lessonsLearned?: string }) => {
    if (!confirmModal.task) return;

    const task = confirmModal.task;
    const newStatus = confirmModal.newStatus;

    // Optimistic Update
    const updatedTasks = tasks.map((t) =>
      t.id === task.id ? { ...t, status: newStatus } : t
    );
    setTasks(updatedTasks);

    try {
      await tasksApi.update(task.id, {
        status: newStatus,
        ...(comment ? { status_comment: comment } : {}),
        ...(completionData?.challenges ? { challenges: completionData.challenges } : {}),
        ...(completionData?.lessonsLearned ? { lessons_learned: completionData.lessonsLearned } : {}),
      });
      showToast('success', `تم نقل المهمة إلى "${taskStatusLabels[newStatus]}"`, 'تم التحديث');
    } catch (error: any) {
      console.error('Failed to update task status:', error);
      setTasks(tasks);
      const errorMessage = error?.message || error?.response?.data?.message || 'فشل في تحديث حالة المهمة';
      showToast('error', errorMessage, 'خطأ');
    }

    setConfirmModal({ isOpen: false, task: null, newStatus: '', confirmationMessage: '' });
    setDraggedTask(null);
  }, [confirmModal, tasks, showToast]);

  // Handle task click to show task view modal
  const handleTaskClick = useCallback((task: TaskType) => {
    setSelectedTaskId(task.id);
    setTaskModalOpen(true);
  }, []);

  // Handle task modal close
  const handleTaskModalClose = useCallback(() => {
    setTaskModalOpen(false);
    setSelectedTaskId(null);
  }, []);

  // Handle task updated from modal - reload tasks
  const handleTaskUpdated = useCallback(async () => {
    try {
      const response = await tasksApi.getAll({ project_id: String(projectId), per_page: String(100) }) as { data?: TaskType[] };
      if (response.data) {
        setTasks(response.data);
      }
    } catch (error) {
      console.error('Failed to refresh tasks:', error);
    }
  }, [projectId]);

  return (
    <div className="space-y-4">
      {/* Header */}
      <TasksHeader
        taskCount={tasks.length}
        viewMode={viewMode}
        showFilters={showFilters}
        activeFiltersCount={activeFiltersCount}
        onViewModeChange={setViewMode}
        onToggleFilters={() => setShowFilters(!showFilters)}
        onCreateTask={() => setCreateTaskModalOpen(true)}
      />

      {/* Filters Panel */}
      {showFilters && (
        <TasksFilters
          tasks={tasks}
          filteredTasks={filteredTasks}
          statusFilter={statusFilter}
          priorityFilter={priorityFilter}
          assigneeFilter={assigneeFilter}
          assignees={assignees}
          activeFiltersCount={activeFiltersCount}
          onStatusFilterChange={setStatusFilter}
          onPriorityFilterChange={setPriorityFilter}
          onAssigneeFilterChange={setAssigneeFilter}
          onResetFilters={resetFilters}
        />
      )}

      {/* Create Task Modal */}
      <CreateTaskModal
        isOpen={createTaskModalOpen}
        onClose={() => setCreateTaskModalOpen(false)}
        projectId={projectId}
        project={{
          id: project.id,
          name: project.name,
          code: project.code,
          start_date: project.start_date || undefined,
          end_date: project.end_date || undefined,
          milestones: project.milestones?.map(m => ({
            id: m.id,
            name: m.name,
            status: m.status,
            start_date: m.start_date || undefined,
            due_date: m.due_date || undefined,
          })),
        }}
        onTaskCreated={onTaskCreated}
      />

      {tasks.length === 0 ? (
        <TasksEmptyState onCreateTask={() => setCreateTaskModalOpen(true)} />
      ) : viewMode === 'kanban' ? (
        <>
          {/* Modals */}
          <StatusTransitionModal
            isOpen={confirmModal.isOpen}
            onClose={() => {
              setConfirmModal({ isOpen: false, task: null, newStatus: '', confirmationMessage: '' });
              setDraggedTask(null);
            }}
            onConfirm={handleConfirmStatusChange}
            taskTitle={confirmModal.task?.title || ''}
            newStatus={confirmModal.newStatus}
            confirmationMessage={confirmModal.confirmationMessage}
            isCompleting={confirmModal.newStatus === 'completed'}
            userRole={getUserRole(confirmModal.task ?? undefined)}
            isImprovement={project.type === 'improvement'}
          />
          <ErrorModal
            isOpen={errorModal.isOpen}
            onClose={() => setErrorModal({ isOpen: false, message: '' })}
            message={errorModal.message}
          />
          <IncompleteSubtasksModal
            isOpen={subtasksWarningModal.isOpen}
            onClose={() => setSubtasksWarningModal({ isOpen: false, taskTitle: '', incompleteSubtasks: [], targetStatus: '' })}
            taskTitle={subtasksWarningModal.taskTitle}
            incompleteSubtasks={subtasksWarningModal.incompleteSubtasks}
            targetStatus={subtasksWarningModal.targetStatus}
          />
          <TaskViewModal
            taskId={selectedTaskId}
            isOpen={taskModalOpen}
            onClose={handleTaskModalClose}
            onTaskUpdated={handleTaskUpdated}
          />
          <KanbanBoard
            tasks={tasks}
            draggedTask={draggedTask}
            dragOverColumn={dragOverColumn}
            expandedSubtasks={expandedSubtasks}
            loadingSubtasks={loadingSubtasks}
            subtasksData={subtasksData}
            openSubtaskStatusMenu={openSubtaskStatusMenu}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            onTaskClick={handleTaskClick}
            onToggleSubtasks={toggleSubtasks}
            onUpdateSubtaskStatus={updateSubtaskStatus}
            onOpenSubtaskStatusMenu={setOpenSubtaskStatusMenu}
          />
        </>
      ) : (
        <TasksListView tasks={filteredTasks} />
      )}
    </div>
  );
};

export default ProjectTasksSection;
