import React, { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { unifiedTasksApi } from '@entities/task';
import {
  Card,
  Button,
  Skeleton,
  StatusBadge,
  Select,
  Checkbox,
} from '@shared/ui';
import {IconPlus, IconSquareCheck, IconUser, IconBuilding, IconBriefcase, IconRefresh, IconFilter, IconLayoutGrid, IconList} from '@tabler/icons-react';
import TaskViewModal from './TaskViewModal';
import type { Task, TaskFilters, PaginationState } from './list';
import type { TaskType } from '@shared/types';

interface PaginatedResponse {
  data: Task[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

interface TaskStats {
  total: number;
  by_status: Record<string, number>;
  by_type: Record<string, number>;
  overdue: number;
  due_today: number;
  due_this_week: number;
}

const MyTasksList: React.FC = () => {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [stats, setStats] = useState<TaskStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [viewMode, setViewMode] = useState<'list' | 'cards'>('list');
  const [pagination, setPagination] = useState<PaginationState>({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  // Modal state
  const [selectedTaskId, setSelectedTaskId] = useState<number | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const [filters, setFilters] = useState<TaskFilters & { type?: string }>({
    search: searchParams.get('search') || '',
    status: searchParams.get('status') || '',
    priority: searchParams.get('priority') || '',
    type: searchParams.get('type') || '',
    my_tasks: true,
    overdue: searchParams.get('overdue') === 'true',
  });

  const fetchTasks = async (page = 1) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(page) };
      if (filters.search) params.search = filters.search;
      if (filters.status) params.status = filters.status;
      if (filters.priority) params.priority = filters.priority;
      if (filters.type) params.type = filters.type;
      if (filters.overdue) params.overdue = 'true';

      const response = (await unifiedTasksApi.getMyTasks(params)) as PaginatedResponse;
      setTasks(response.data);
      setPagination({
        currentPage: response.current_page,
        lastPage: response.last_page,
        total: response.total,
      });
    } catch (error) {
      console.error('Failed to fetch tasks:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchStats = async () => {
    try {
      const response = await unifiedTasksApi.getStats();
      setStats(response as TaskStats);
    } catch (error) {
      console.error('Failed to fetch stats:', error);
    }
  };

  useEffect(() => {
    fetchTasks();
    fetchStats();
  }, []);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.type) params.set('type', filters.type);
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
    fetchStats();
  };

  const isOverdue = (task: Task): boolean => {
    return (
      task.status !== 'completed' &&
      !!task.due_date &&
      new Date(task.due_date) < new Date()
    );
  };

  const getTaskTypeIcon = (type?: TaskType) => {
    switch (type) {
      case 'personal':
        return <IconUser className="h-4 w-4" />;
      case 'department':
        return <IconBuilding className="h-4 w-4" />;
      case 'recurring':
        return <IconRefresh className="h-4 w-4" />;
      default:
        return <IconBriefcase className="h-4 w-4" />;
    }
  };

  const statusOptions = [
    { value: '', label: t('tasks.all_statuses') },
    { value: 'todo', label: t('status.todo') },
    { value: 'in_progress', label: t('status.in_progress') },
    { value: 'in_review', label: t('status.in_review') },
    { value: 'completed', label: t('status.completed') },
    { value: 'on_hold', label: t('status.on_hold') },
  ];

  const priorityOptions = [
    { value: '', label: t('tasks.all_priorities') },
    { value: 'low', label: t('priority.low') },
    { value: 'medium', label: t('priority.medium') },
    { value: 'high', label: t('priority.high') },
    { value: 'critical', label: t('priority.critical') },
  ];

  const typeOptions = [
    { value: '', label: t('tasks.all_types') },
    { value: 'project', label: t('task_type.project') },
    { value: 'personal', label: t('task_type.personal') },
    { value: 'department', label: t('task_type.department') },
    { value: 'recurring', label: t('task_type.recurring') },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[var(--text-primary)]">{t('tasks.my_tasks')}</h1>
          <p className="text-[var(--text-secondary)] mt-1">
            {t('tasks.my_tasks_description')}
          </p>
        </div>
        <Link to="/tasks/create">
          <Button leftIcon={<IconPlus className="h-4 w-4" />}>
            {t('tasks.new_task')}
          </Button>
        </Link>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card className="p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-[var(--accent-subtle)]">
                <IconSquareCheck className="h-5 w-5 text-[var(--accent-default)]" />
              </div>
              <div>
                <p className="text-2xl font-bold text-[var(--text-primary)]">{stats.total}</p>
                <p className="text-sm text-[var(--text-secondary)]">{t('tasks.total_tasks')}</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-[var(--status-warning-subtle)]">
                <IconRefresh className="h-5 w-5 text-[var(--status-warning)]" />
              </div>
              <div>
                <p className="text-2xl font-bold text-[var(--text-primary)]">{stats.by_status?.in_progress || 0}</p>
                <p className="text-sm text-[var(--text-secondary)]">{t('status.in_progress')}</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-[var(--status-danger-subtle)]">
                <IconSquareCheck className="h-5 w-5 text-[var(--status-danger)]" />
              </div>
              <div>
                <p className="text-2xl font-bold text-[var(--text-primary)]">{stats.overdue || 0}</p>
                <p className="text-sm text-[var(--text-secondary)]">{t('tasks.overdue')}</p>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-[var(--status-success-subtle)]">
                <IconSquareCheck className="h-5 w-5 text-[var(--status-success)]" />
              </div>
              <div>
                <p className="text-2xl font-bold text-[var(--text-primary)]">{stats.by_status?.completed || 0}</p>
                <p className="text-sm text-[var(--text-secondary)]">{t('status.completed')}</p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Filters */}
      <Card className="p-4">
        <form onSubmit={handleSearch} className="space-y-4">
          <div className="flex flex-wrap items-center gap-4">
            {/* Search */}
            <div className="flex-1 min-w-[200px]">
              <input
                type="text"
                placeholder={t('tasks.search_placeholder')}
                value={filters.search}
                onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                className="w-full px-4 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-muted)] text-[var(--text-primary)]"
              />
            </div>

            {/* Type IconFilter */}
            <Select
              value={filters.type}
              onChange={(e) => setFilters({ ...filters, type: e.target.value })}
              className="min-w-[160px]"
              options={typeOptions.map((opt) => ({
                value: opt.value,
                label: opt.label,
              }))}
            />

            {/* Status IconFilter */}
            <Select
              value={filters.status}
              onChange={(e) => setFilters({ ...filters, status: e.target.value })}
              className="min-w-[160px]"
              options={statusOptions.map((opt) => ({
                value: opt.value,
                label: opt.label,
              }))}
            />

            {/* Priority IconFilter */}
            <Select
              value={filters.priority}
              onChange={(e) => setFilters({ ...filters, priority: e.target.value })}
              className="min-w-[160px]"
              options={priorityOptions.map((opt) => ({
                value: opt.value,
                label: opt.label,
              }))}
            />

            {/* Overdue */}
            <Checkbox
              checked={filters.overdue}
              onChange={(e) => setFilters({ ...filters, overdue: e.target.checked })}
              label={t('tasks.overdue_only')}
            />

            <Button type="submit" variant="secondary" leftIcon={<IconFilter className="h-4 w-4" />}>
              {t('tasks.apply')}
            </Button>
          </div>

          {/* View Mode Toggle */}
          <div className="flex items-center gap-2 border-t border-[var(--border-default)] pt-4">
            <span className="text-sm text-[var(--text-secondary)]">{t('tasks.view_mode')}:</span>
            <button
              type="button"
              onClick={() => setViewMode('list')}
              className={`p-2 rounded-lg ${viewMode === 'list' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]'}`}
            >
              <IconList className="h-5 w-5" />
            </button>
            <button
              type="button"
              onClick={() => setViewMode('cards')}
              className={`p-2 rounded-lg ${viewMode === 'cards' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]'}`}
            >
              <IconLayoutGrid className="h-5 w-5" />
            </button>
          </div>
        </form>
      </Card>

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
            <p className="text-[var(--text-tertiary)] mb-4">{t('tasks.no_tasks_assigned')}</p>
            <Link to="/tasks/create">
              <Button leftIcon={<IconPlus className="h-4 w-4" />}>
                {t('tasks.create_new')}
              </Button>
            </Link>
          </div>
        </Card>
      ) : viewMode === 'list' ? (
        <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
          <div className="divide-y divide-[var(--border-default)]">
            {tasks.map((task) => (
              <div
                key={task.id}
                onClick={() => openTaskModal(task.id)}
                className="p-4 hover:bg-[var(--surface-subtle)] cursor-pointer transition-colors"
              >
                <div className="flex items-start gap-4">
                  {/* Type Icon */}
                  <div className={`p-2 rounded-lg ${
                    task.type === 'personal' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                    task.type === 'department' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                    task.type === 'recurring' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                    'bg-[var(--surface-muted)] text-[var(--text-secondary)]'
                  }`}>
                    {getTaskTypeIcon(task.type as TaskType)}
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <h3 className="font-medium text-[var(--text-primary)] truncate">{task.title}</h3>
                      {isOverdue(task) && (
                        <span className="px-2 py-0 text-xs rounded-full bg-[var(--status-danger-subtle)] text-[var(--status-danger)]">
                          {t('tasks.overdue')}
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-4 text-sm text-[var(--text-secondary)]">
                      {task.project && (
                        <span className="flex items-center gap-1">
                          <IconBriefcase className="h-3.5 w-3.5" />
                          {task.project.name}
                        </span>
                      )}
                      {task.due_date && (
                        <span>
                          {new Date(task.due_date).toLocaleDateString('ar-EG-u-nu-latn')}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Status & Priority */}
                  <div className="flex items-center gap-2">
                    <StatusBadge type="task" status={task.status} size="sm" />
                    <StatusBadge type="priority" status={task.priority} size="sm" />
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Pagination */}
          {pagination.lastPage > 1 && (
            <div className="flex items-center justify-center gap-2 p-4 border-t border-[var(--border-default)]">
              <Button
                variant="ghost"
                size="sm"
                disabled={pagination.currentPage === 1}
                onClick={() => handlePageChange(pagination.currentPage - 1)}
              >
                {t('common.previous')}
              </Button>
              <span className="text-sm text-[var(--text-secondary)]">
                {t('tasks.page_of', { current: pagination.currentPage, total: pagination.lastPage })}
              </span>
              <Button
                variant="ghost"
                size="sm"
                disabled={pagination.currentPage === pagination.lastPage}
                onClick={() => handlePageChange(pagination.currentPage + 1)}
              >
                {t('common.next')}
              </Button>
            </div>
          )}
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {tasks.map((task) => (
            <Card
              key={task.id}
              onClick={() => openTaskModal(task.id)}
              className="p-4 cursor-pointer hover:shadow-md transition-shadow"
            >
              <div className="flex items-start justify-between mb-3">
                <div className={`p-2 rounded-lg ${
                  task.type === 'personal' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                  task.type === 'department' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                  task.type === 'recurring' ? 'bg-[var(--accent-subtle)] text-[var(--accent-default)]' :
                  'bg-[var(--surface-muted)] text-[var(--text-secondary)]'
                }`}>
                  {getTaskTypeIcon(task.type as TaskType)}
                </div>
                <StatusBadge type="task" status={task.status} size="sm" />
              </div>

              <h3 className="font-medium text-[var(--text-primary)] mb-2 line-clamp-2">{task.title}</h3>

              {task.project && (
                <p className="text-sm text-[var(--text-secondary)] mb-2 flex items-center gap-1">
                  <IconBriefcase className="h-3.5 w-3.5" />
                  {task.project.name}
                </p>
              )}

              <div className="flex items-center justify-between mt-4 pt-3 border-t border-[var(--border-default)]">
                <StatusBadge type="priority" status={task.priority} size="sm" />
                {task.due_date && (
                  <span className={`text-xs ${isOverdue(task) ? 'text-[var(--status-danger)]' : 'text-[var(--text-secondary)]'}`}>
                    {new Date(task.due_date).toLocaleDateString('ar-EG-u-nu-latn')}
                  </span>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Task View Modal */}
      <TaskViewModal
        taskId={selectedTaskId}
        isOpen={isModalOpen}
        onClose={closeTaskModal}
        onTaskUpdated={handleTaskUpdated}
      />
    </div>
  );
};

export default MyTasksList;
