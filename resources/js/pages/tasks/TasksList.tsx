import React, { useEffect, useState, useCallback } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { tasksApi } from '@entities/task';
import {
  Card,
  Button,
  Skeleton,
  PageHeader,
  StatStrip,
  IconPlus,
  IconSquareCheck,
  IconFilter,
} from '@shared/ui';
import { DeleteConfirmationModal } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import TaskViewModal from './TaskViewModal';

const StatusTransitionModal = React.lazy(() => import('@pages/projects/components/modals/StatusTransitionModal'));
import {
  KanbanView,
  CardsView,
  TableView,
  TasksFilters,
  TasksFiltersCard,
} from './list';
import type { Task, SubTask, PaginatedResponse, TaskFilters, PaginationState } from './list';

const TasksList: React.FC = () => {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const { showToast } = useToast();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [loading, setLoading] = useState(true);
  const [viewMode, setViewMode] = useState<'table' | 'cards' | 'kanban'>('table');
  const [pagination, setPagination] = useState<PaginationState>({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  // Modal state
  const [selectedTaskId, setSelectedTaskId] = useState<number | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  // Delete state
  const [taskToDelete, setTaskToDelete] = useState<Task | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // Drag and Drop state
  const [draggedTask, setDraggedTask] = useState<Task | null>(null);
  const [dragOverColumn, setDragOverColumn] = useState<string | null>(null);
  const [transitionModal, setTransitionModal] = useState<{ task: Task; newStatus: string } | null>(null);

  // Subtasks dropdown state
  const [expandedSubtasks, setExpandedSubtasks] = useState<number | null>(null);
  const [loadingSubtasks, setLoadingSubtasks] = useState<number | null>(null);
  const [subtasksData, setSubtasksData] = useState<Record<number, SubTask[]>>({});

  // Filters visibility state
  const [showFilters, setShowFilters] = useState(false);

  const [filters, setFilters] = useState<TaskFilters>({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
    priority: searchParams.get('priority') || '',
    my_tasks: searchParams.get('my_tasks') === 'true',
    overdue: searchParams.get('overdue') === 'true',
  });

  const fetchTasks = async (page = 1) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(page) };
      if (filters.search) params.search = filters.search;
      if (filters.status) params.status = filters.status;
      if (filters.priority) params.priority = filters.priority;
      if (filters.my_tasks) params.my_tasks = 'true';
      if (filters.overdue) params.overdue = 'true';

      const response = (await tasksApi.getAll(params)) as PaginatedResponse;
      setTasks(response.data);
      // Unified API nests pagination under `meta`; fall back to flattened fields.
      const meta = response.meta;
      setPagination({
        currentPage: meta?.current_page ?? response.current_page ?? 1,
        lastPage: meta?.last_page ?? response.last_page ?? 1,
        total: meta?.total ?? response.total ?? 0,
      });
    } catch (error) {
      console.error('Failed to fetch tasks:', error);
    } finally {
      setLoading(false);
    }
  };

  // إعادة جلب المهام عند تغيير الفلاتر السريعة
  useEffect(() => {
    fetchTasks(1);
  }, [filters.my_tasks, filters.overdue, filters.status, filters.priority]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.my_tasks) params.set('my_tasks', 'true');
    if (filters.overdue) params.set('overdue', 'true');
    setSearchParams(params);
    fetchTasks(1);
  };

  const handlePageChange = (page: number) => {
    fetchTasks(page);
  };

  const openTaskModal = (taskId: number) => {
    setSelectedTaskId(taskId);
    setIsModalOpen(true);
  };

  const closeTaskModal = () => {
    setIsModalOpen(false);
    setSelectedTaskId(null);
  };

  const handleTaskUpdated = () => {
    fetchTasks(pagination.currentPage);
  };

  // Delete handlers
  const handleDeleteClick = (task: Task) => {
    setTaskToDelete(task);
  };

  const handleDeleteConfirm = async () => {
    if (!taskToDelete) return;

    setIsDeleting(true);
    try {
      await tasksApi.delete(taskToDelete.id);
      showToast('success', t('tasks.delete_success'));
      fetchTasks(pagination.currentPage);
    } catch (error: any) {
      showToast('error', error.message || t('tasks.delete_error'));
    } finally {
      setIsDeleting(false);
      setTaskToDelete(null);
    }
  };

  const handleDeleteCancel = () => {
    setTaskToDelete(null);
  };

  const isOverdue = (task: Task): boolean => {
    return (
      task.status !== 'completed' &&
      !!task.due_date &&
      new Date(task.due_date) < new Date()
    );
  };

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
      // تحديث البيانات المحلية
      setSubtasksData((prev) => ({
        ...prev,
        [parentId]: prev[parentId].map((st) =>
          st.id === subtaskId ? { ...st, status: newStatus } : st
        ),
      }));
      // إعادة جلب المهام لتحديث العدد
      fetchTasks(pagination.currentPage);
    } catch (error) {
      console.error('Failed to update subtask status:', error);
    }
  };

  // Drag and Drop handlers
  const handleDragStart = useCallback((e: React.DragEvent, task: Task) => {
    setDraggedTask(task);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', task.id.toString());
    // تأخير لتفعيل تأثير السحب
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

  const applyStatusChange = useCallback(async (
    task: Task,
    newStatus: string,
    docs?: { status_comment?: string; lessons_learned?: string },
  ) => {
    const previous = tasks;
    setTasks(tasks.map((t) => (t.id === task.id ? { ...t, status: newStatus } : t)));

    try {
      await tasksApi.update(task.id, { status: newStatus, ...docs });
    } catch (error: any) {
      setTasks(previous);
      const message = error?.message || error?.response?.data?.message || t('tasks.status_update_failed');
      showToast('error', message);
      throw error;
    }
  }, [tasks, showToast, t]);

  const handleDrop = useCallback(async (e: React.DragEvent, newStatus: string) => {
    e.preventDefault();
    setDragOverColumn(null);

    if (!draggedTask || draggedTask.status === newStatus) {
      setDraggedTask(null);
      return;
    }

    const task = draggedTask;
    setDraggedTask(null);

    // مشاريع التحسين: توثيق PDCA إلزامي عند المراجعة/الإكمال — افتح المودال
    const isImprovement = task.project?.type === 'improvement';
    if (isImprovement && (newStatus === 'in_review' || newStatus === 'completed')) {
      setTransitionModal({ task, newStatus });
      return;
    }

    try {
      await applyStatusChange(task, newStatus);
    } catch {
      // التراجع ورسالة الخطأ يُعالجان في applyStatusChange
    }
  }, [draggedTask, applyStatusChange]);

  const handleConfirmTransition = useCallback(async (comment: string, completionData?: { lessonsLearned?: string }) => {
    if (!transitionModal) return;
    const { task, newStatus } = transitionModal;

    try {
      await applyStatusChange(task, newStatus, {
        ...(comment ? { status_comment: comment } : {}),
        ...(completionData?.lessonsLearned ? { lessons_learned: completionData.lessonsLearned } : {}),
      });
      setTransitionModal(null);
    } catch {
      setTransitionModal(null);
    }
  }, [transitionModal, applyStatusChange]);

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Header */}
      <PageHeader
        icon={IconSquareCheck}
        iconTone="task"
        title={t('tasks.title')}
        description={t('tasks.manage_description')}
        actions={
          <>
            <Button
              variant={showFilters ? 'secondary' : 'outline'}
              size="sm"
              leftIcon={<IconFilter className="h-4 w-4" />}
              onClick={() => setShowFilters(!showFilters)}
            >
              {t('common.filter')}
            </Button>
            <Link to="/tasks/create">
              <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
                {t('tasks.new_task')}
              </Button>
            </Link>
          </>
        }
      />

      {/* Stats */}
      <StatStrip
        items={[
          { label: t('tasks.total_tasks'), value: pagination.total, tone: 'accent' },
          {
            label: t('status.in_progress'),
            value: tasks.filter((tk) => tk.status === 'in_progress').length,
            tone: 'accent',
          },
          {
            label: t('status.completed'),
            value: tasks.filter((tk) => tk.status === 'completed').length,
            tone: 'success',
          },
          {
            label: t('tasks.overdue'),
            value: tasks.filter((tk) => isOverdue(tk)).length,
            tone: 'danger',
          },
        ]}
      />

      {/* Quick Filters & View Toggle */}
      <TasksFilters
        filters={filters}
        viewMode={viewMode}
        onFiltersChange={setFilters}
        onViewModeChange={setViewMode}
      />

      {/* Filters Card */}
      {showFilters && (
        <TasksFiltersCard
          filters={filters}
          onFiltersChange={setFilters}
          onSearch={handleSearch}
          onClose={() => setShowFilters(false)}
        />
      )}

      {/* Content */}
      {loading ? (
        <Card className="p-0 border border-[var(--border-default)]">
          <div className="p-6 space-y-4">
            {[1, 2, 3, 4, 5].map((i) => (
              <div key={i} className="flex items-center gap-4">
                <Skeleton className="h-10 w-10 rounded-lg" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-1/3" />
                  <Skeleton className="h-3 w-1/4" />
                </div>
                <Skeleton className="h-6 w-20 rounded-full" />
              </div>
            ))}
          </div>
        </Card>
      ) : tasks.length === 0 ? (
        <Card className="border border-[var(--border-default)]">
          <div className="text-center py-12 px-6">
            <IconSquareCheck className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
            <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('tasks.no_tasks')}</h3>
            <p className="text-[var(--text-tertiary)] mb-4">{t('tasks.start_first')}</p>
            <Link to="/tasks/create">
              <Button leftIcon={<IconPlus className="h-4 w-4" />}>
                {t('tasks.create_new')}
              </Button>
            </Link>
          </div>
        </Card>
      ) : viewMode === 'kanban' ? (
        <KanbanView
          tasks={tasks}
          draggedTask={draggedTask}
          dragOverColumn={dragOverColumn}
          expandedSubtasks={expandedSubtasks}
          loadingSubtasks={loadingSubtasks}
          subtasksData={subtasksData}
          onDragStart={handleDragStart}
          onDragEnd={handleDragEnd}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
          onOpenTaskModal={openTaskModal}
          onToggleSubtasks={toggleSubtasks}
          onUpdateSubtaskStatus={updateSubtaskStatus}
          isOverdue={isOverdue}
        />
      ) : viewMode === 'cards' ? (
        <CardsView
          tasks={tasks}
          pagination={pagination}
          onOpenTaskModal={openTaskModal}
          onPageChange={handlePageChange}
          isOverdue={isOverdue}
        />
      ) : (
        <TableView
          tasks={tasks}
          pagination={pagination}
          onOpenTaskModal={openTaskModal}
          onPageChange={handlePageChange}
          onDelete={handleDeleteClick}
          isOverdue={isOverdue}
        />
      )}

      {/* Task View Modal */}
      <TaskViewModal
        taskId={selectedTaskId}
        isOpen={isModalOpen}
        onClose={closeTaskModal}
        onTaskUpdated={handleTaskUpdated}
      />

      {/* Delete Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={!!taskToDelete}
        item={taskToDelete}
        title={t('tasks.delete')}
        itemName={taskToDelete?.title || ''}
        warningMessage={t('tasks.delete_warning')}
        isDeleting={isDeleting}
        onClose={handleDeleteCancel}
        onConfirm={handleDeleteConfirm}
      />

      {transitionModal && (
        <React.Suspense fallback={null}>
          <StatusTransitionModal
            isOpen
            onClose={() => setTransitionModal(null)}
            onConfirm={handleConfirmTransition}
            taskTitle={transitionModal.task.title}
            newStatus={transitionModal.newStatus}
            confirmationMessage={t('projects.confirm_status_change')}
            isCompleting={transitionModal.newStatus === 'completed'}
            isImprovement
          />
        </React.Suspense>
      )}
    </div>
  );
};

export default TasksList;
