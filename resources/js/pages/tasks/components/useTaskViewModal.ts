import { useEffect, useState, useCallback } from 'react';
import { tasksApi } from '@entities/task';
import { usersApi } from '@entities/user';
import type { TaskDetails, UserOption } from './types';

interface UseTaskViewModalOptions {
  taskId: number | null;
  isOpen: boolean;
  onTaskUpdated?: () => void;
}

export function useTaskViewModal({ taskId, isOpen, onTaskUpdated }: UseTaskViewModalOptions) {
  const [task, setTask] = useState<TaskDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState('details');

  // State for adding subtask
  const [showSubtaskForm, setShowSubtaskForm] = useState(false);
  const [subtaskTitle, setSubtaskTitle] = useState('');
  const [subtaskAssignee, setSubtaskAssignee] = useState<string>('');
  const [isAddingSubtask, setIsAddingSubtask] = useState(false);
  const [subtaskUsers, setSubtaskUsers] = useState<UserOption[]>([]);

  // تحميل المهمة مع إظهار loading (للتحميل الأول فقط)
  const fetchTask = useCallback(async () => {
    if (!taskId) return;
    setLoading(true);
    try {
      const response = await tasksApi.getOne(taskId) as { data?: TaskDetails } & TaskDetails;
      const taskData = response.data || response;
      setTask(taskData as TaskDetails);
      setError(null);
    } catch (err) {
      setError('فشل في تحميل بيانات المهمة');
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [taskId]);

  // تحديث صامت للمهمة (بدون loading)
  const refreshTask = useCallback(async () => {
    if (!taskId) return;
    try {
      const response = await tasksApi.getOne(taskId) as { data?: TaskDetails } & TaskDetails;
      const taskData = response.data || response;
      setTask(taskData as TaskDetails);
    } catch (err) {
      console.error('Failed to refresh task:', err);
    }
  }, [taskId]);

  useEffect(() => {
    if (isOpen && taskId) {
      fetchTask();
      setActiveTab('details');
    }
  }, [isOpen, taskId, fetchTask]);

  // Fetch users for subtask assignment
  useEffect(() => {
    if (showSubtaskForm || (task?.subtasks && task.subtasks.length > 0)) {
      usersApi.getList().then((response: unknown) => {
        const data = response as { data?: UserOption[] } | UserOption[];
        setSubtaskUsers((data as { data?: UserOption[] }).data || data as UserOption[] || []);
      }).catch(console.error);
    }
  }, [showSubtaskForm, task?.subtasks]);

  const handleStatusChange = (newStatus: string) => {
    if (task) {
      setTask({ ...task, status: newStatus });
    }
    onTaskUpdated?.();
  };

  // Handle adding subtask
  const handleAddSubtask = async () => {
    if (!subtaskTitle.trim() || !task) return;

    setIsAddingSubtask(true);
    try {
      const response = await tasksApi.create({
        project_id: task.project?.id,
        parent_id: task.id,
        title: subtaskTitle.trim(),
        priority: task.priority,
        due_date: task.due_date,
        assigned_to: subtaskAssignee ? Number(subtaskAssignee) : undefined,
      });

      const newSubtask = (response as { task?: TaskDetails }).task || response;
      if (newSubtask) {
        const subtaskData = newSubtask as TaskDetails & { assignee?: UserOption | null };
        setTask({
          ...task,
          subtasks: [...task.subtasks, {
            id: subtaskData.id,
            title: subtaskData.title,
            status: subtaskData.status || 'todo',
            priority: subtaskData.priority,
            due_date: subtaskData.due_date,
            assignee: subtaskData.assignee || null,
          }],
        });
      }

      setSubtaskTitle('');
      setSubtaskAssignee('');
      setShowSubtaskForm(false);
      onTaskUpdated?.();
    } catch (err) {
      console.error('Failed to create subtask:', err);
    } finally {
      setIsAddingSubtask(false);
    }
  };

  // Handle subtask status change
  const handleSubtaskStatusChange = async (subtaskId: number, newStatus: string) => {
    if (!task) return;

    setTask({
      ...task,
      subtasks: task.subtasks.map(st =>
        st.id === subtaskId ? { ...st, status: newStatus } : st
      ),
    });

    try {
      await tasksApi.updateStatus(subtaskId, newStatus);
      onTaskUpdated?.();
    } catch (err) {
      console.error('Failed to update subtask status:', err);
      refreshTask();
    }
  };

  // Handle subtask update
  const handleSubtaskUpdate = async (subtaskId: number, data: { title?: string; assignee?: number | null; priority?: string }) => {
    if (!task) return;

    setTask({
      ...task,
      subtasks: task.subtasks.map(st =>
        st.id === subtaskId
          ? {
              ...st,
              title: data.title || st.title,
              priority: data.priority || st.priority,
              assignee: data.assignee
                ? subtaskUsers.find(u => u.id === data.assignee) || st.assignee
                : null,
            }
          : st
      ),
    });

    try {
      await tasksApi.update(subtaskId, {
        title: data.title,
        assigned_to: data.assignee,
        priority: data.priority,
      });
      onTaskUpdated?.();
    } catch (err) {
      console.error('Failed to update subtask:', err);
      refreshTask();
    }
  };

  // Handle subtask delete
  const handleSubtaskDelete = async (subtaskId: number) => {
    if (!task) return;

    const previousSubtasks = task.subtasks;
    setTask({
      ...task,
      subtasks: task.subtasks.filter(st => st.id !== subtaskId),
    });

    try {
      await tasksApi.delete(subtaskId);
      onTaskUpdated?.();
    } catch (err) {
      console.error('Failed to delete subtask:', err);
      setTask({ ...task, subtasks: previousSubtasks });
    }
  };

  const isOverdue = task?.due_date &&
    new Date(task.due_date) < new Date() &&
    task.status !== 'completed';

  return {
    // Task state
    task,
    loading,
    error,
    isOverdue,

    // Tab state
    activeTab,
    setActiveTab,

    // Subtask state
    showSubtaskForm,
    setShowSubtaskForm,
    subtaskTitle,
    setSubtaskTitle,
    subtaskAssignee,
    setSubtaskAssignee,
    isAddingSubtask,
    subtaskUsers,

    // Handlers
    handleStatusChange,
    handleAddSubtask,
    handleSubtaskStatusChange,
    handleSubtaskUpdate,
    handleSubtaskDelete,
  };
}
