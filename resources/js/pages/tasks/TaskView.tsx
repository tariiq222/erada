import React, { useEffect, useState, useCallback } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { tasksApi } from '@entities/task';
import {
  Card,
  CardHeader,
  CardTitle,
  CardContent,
  Button,
  Breadcrumb,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  PageHeader,
  EmptyState,
} from '@shared/ui';
import {
  TaskStatusChanger,
  TaskTimeIndicator,
  TaskActivityLog,
  TaskDetailsPanel,
} from '@widgets/task';
import {IconEdit, IconCircle, IconArrowRight, IconLayoutKanban, IconTarget, IconMessage, IconAlertTriangle, IconListTree, IconActivity, IconInfoCircle, IconPlus, IconClipboardCheck} from '@tabler/icons-react';
import {
  CommentsSection,
  TaskViewSkeleton,
  statusLabels,
  statusColors,
  statusIcons,
} from './view';
import type { TaskDetails } from './view';

const TaskView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [task, setTask] = useState<TaskDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // State for adding subtask
  const [showSubtaskForm, setShowSubtaskForm] = useState(false);
  const [subtaskTitle, setSubtaskTitle] = useState('');
  const [isAddingSubtask, setIsAddingSubtask] = useState(false);

  const fetchTask = useCallback(async () => {
    if (!id) return;
    try {
      const response = await tasksApi.getOne(parseInt(id)) as { data?: TaskDetails } & TaskDetails;
      // Laravel JsonResource يرجع البيانات داخل data wrapper
      const taskData = response.data || response;
      setTask(taskData as TaskDetails);
    } catch (err) {
      setError(t('tasks.load_error'));
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchTask();
  }, [fetchTask]);

  const handleStatusChange = (newStatus: string) => {
    if (task) {
      setTask({ ...task, status: newStatus });
    }
  };

  const handleAddSubtask = async () => {
    if (!subtaskTitle.trim() || !task) return;

    setIsAddingSubtask(true);
    try {
      await tasksApi.create({
        project_id: task.project?.id,
        parent_id: task.id,
        title: subtaskTitle.trim(),
        priority: task.priority,
        due_date: task.due_date,
      });
      setSubtaskTitle('');
      setShowSubtaskForm(false);
      fetchTask();
    } catch (err) {
      console.error('Failed to create subtask:', err);
    } finally {
      setIsAddingSubtask(false);
    }
  };

  const isOverdue = task?.due_date &&
    new Date(task.due_date) < new Date() &&
    task.status !== 'completed';

  if (loading) {
    return <TaskViewSkeleton />;
  }

  if (error || !task) {
    return (
      <div className="text-center py-12">
        <IconAlertTriangle className="h-12 w-12 text-[var(--status-danger)] mx-auto mb-4" />
        <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">
          {error || t('tasks.not_found')}
        </h3>
        <Button onClick={() => navigate('/tasks')}>
          {t('tasks.back_to_list')}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="bg-[var(--surface-base)] rounded-2xl shadow-sm border border-[var(--border-default)] p-4 sm:p-6">
        <PageHeader
          icon={IconClipboardCheck}
          iconTone="task"
          title={task.title}
          breadcrumb={
            <Breadcrumb
              items={[
                { label: t('tasks.title'), href: '/tasks' },
                ...(task.project ? [{ label: task.project.name, href: `/projects/${task.project.id}` }] : []),
                { label: task.title },
              ]}
            />
          }
          status={
            <div className="flex flex-wrap items-center gap-2">
              {task.project && (
                <Link
                  to={`/projects/${task.project.id}`}
                  className="inline-flex items-center gap-1 px-3 py-1 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded-lg text-sm font-medium transition-colors"
                >
                  <IconLayoutKanban className="h-4 w-4" />
                  {task.project.code}
                </Link>
              )}
              {isOverdue && (
                <div className="inline-flex items-center gap-1 px-3 py-1 bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)] rounded-lg text-sm font-medium">
                  <IconAlertTriangle className="h-4 w-4" />
                  {t('tasks.overdue')}
                </div>
              )}
              {task.parent && (
                <Link
                  to={`/tasks/${task.parent.id}`}
                  className="inline-flex items-center gap-1 px-3 py-1 bg-[var(--surface-muted)] text-[var(--text-primary)] rounded-lg text-sm font-medium hover:bg-[var(--surface-subtle)] transition-colors"
                >
                  <IconListTree className="h-4 w-4" />
                  {task.parent.title}
                </Link>
              )}
            </div>
          }
          metadata={
            task.milestone ? (
              <span className="inline-flex items-center gap-2">
                <IconTarget className="h-4 w-4" />
                {task.milestone.name}
              </span>
            ) : undefined
          }
          actions={
            <>
              {/* Status changes are a leadership capability (tasks.edit); hide the
                  control for users the API would 403 (e.g. plain project members). */}
              {task.abilities?.edit && (
                <TaskStatusChanger
                  taskId={task.id}
                  currentStatus={task.status}
                  onStatusChange={handleStatusChange}
                  size="lg"
                  hasProject={!!task.project}
                  subtasks={task.subtasks}
                  isImprovement={task.project?.type === 'improvement'}
                  taskTitle={task.title}
                />
              )}
              <Link to={`/tasks/${task.id}/edit`}>
                <Button leftIcon={<IconEdit className="h-4 w-4" />} variant="outline">
                  {t('common.edit')}
                </Button>
              </Link>
            </>
          }
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          <Tabs defaultValue="details">
            <TabsList className="mb-4">
              <TabsTrigger value="details" icon={<IconInfoCircle className="h-4 w-4" />}>
                {t('common.details')}
              </TabsTrigger>
              <TabsTrigger value="subtasks" icon={<IconListTree className="h-4 w-4" />}>
                {t('tasks.subtasks')} ({task.subtasks?.length || 0})
              </TabsTrigger>
              <TabsTrigger value="comments" icon={<IconMessage className="h-4 w-4" />}>
                {t('tasks.comments')} ({task.comments?.length || 0})
              </TabsTrigger>
              <TabsTrigger value="activity" icon={<IconActivity className="h-4 w-4" />}>
                {t('tasks.activities')}
              </TabsTrigger>
            </TabsList>

            {/* Details Tab */}
            <TabsContent value="details">
              <Card>
                <CardHeader>
                  <CardTitle>{t('tasks.description')}</CardTitle>
                </CardHeader>
                <CardContent>
                  {task.description ? (
                    <p className="text-[var(--text-primary)] whitespace-pre-wrap leading-relaxed">
                      {task.description}
                    </p>
                  ) : (
                    <p className="text-[var(--text-tertiary)] text-center py-4">{t('tasks.no_description')}</p>
                  )}
                </CardContent>
              </Card>

              {/* Time Indicator */}
              {task.time_indicator?.has_due_date && (
                <TaskTimeIndicator
                  indicator={task.time_indicator}
                  taskStatus={task.status}
                  variant="detailed"
                  className="mt-6"
                />
              )}
            </TabsContent>

            {/* Subtasks Tab */}
            <TabsContent value="subtasks">
              <Card>
                <CardHeader>
                  <div className="flex items-center justify-between">
                    <CardTitle>{t('tasks.subtasks')}</CardTitle>
                    {!showSubtaskForm && (
                      <Button
                        onClick={() => setShowSubtaskForm(true)}
                        size="sm"
                        leftIcon={<IconPlus className="h-4 w-4" />}
                      >
                        {t('common.add')}
                      </Button>
                    )}
                  </div>
                </CardHeader>
                <CardContent>
                  {/* Add Subtask Form */}
                  {showSubtaskForm && (
                    <div className="mb-4 bg-[var(--surface-subtle)] rounded-xl p-4 space-y-3">
                      <input
                        type="text"
                        value={subtaskTitle}
                        onChange={(e) => setSubtaskTitle(e.target.value)}
                        placeholder={t('tasks.subtask_title_placeholder')}
                        className="w-full px-4 py-2 border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] text-sm bg-[var(--surface-base)] text-[var(--text-primary)]"
                        dir="auto"
                        autoFocus
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            handleAddSubtask();
                          }
                          if (e.key === 'Escape') {
                            setShowSubtaskForm(false);
                            setSubtaskTitle('');
                          }
                        }}
                      />
                      <div className="flex items-center gap-2 justify-end">
                        <Button
                          onClick={() => {
                            setShowSubtaskForm(false);
                            setSubtaskTitle('');
                          }}
                          variant="ghost"
                          size="sm"
                        >
                          {t('common.cancel')}
                        </Button>
                        <Button
                          onClick={handleAddSubtask}
                          disabled={!subtaskTitle.trim() || isAddingSubtask}
                          size="sm"
                        >
                          {isAddingSubtask ? t('tasks.adding') : t('common.add')}
                        </Button>
                      </div>
                    </div>
                  )}

                  {/* Subtasks List */}
                  {task.subtasks && task.subtasks.length > 0 ? (
                    <div className="space-y-2">
                      {task.subtasks.map((subtask) => {
                        const SubIcon = statusIcons[subtask.status] || IconCircle;
                        return (
                          <Link
                            key={subtask.id}
                            to={`/tasks/${subtask.id}`}
                            className="flex items-center gap-3 p-4 bg-[var(--surface-subtle)] rounded-xl hover:bg-[var(--surface-muted)] transition-colors border border-transparent hover:border-[var(--accent-subtle)]"
                          >
                            <div className={`p-2 rounded-lg ${statusColors[subtask.status]}`}>
                              <SubIcon className="h-4 w-4" />
                            </div>
                            <span className="flex-1 text-[var(--text-primary)] font-medium">{subtask.title}</span>
                            <span className={`text-xs px-2 py-1 rounded-lg ${statusColors[subtask.status]}`}>
                              {t(statusLabels[subtask.status])}
                            </span>
                            <IconArrowRight className="h-4 w-4 text-[var(--text-tertiary)] rtl:rotate-180" />
                          </Link>
                        );
                      })}
                    </div>
                  ) : !showSubtaskForm && (
                    <EmptyState
                      icon={IconListTree}
                      title={t('tasks.no_subtasks')}
                      description={t('tasks.add_subtasks_hint')}
                      size="md"
                    />
                  )}
                </CardContent>
              </Card>
            </TabsContent>

            {/* Comments Tab */}
            <TabsContent value="comments">
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <IconMessage className="h-5 w-5 text-[var(--accent-default)]" />
                    {t('tasks.comments')} ({task.comments?.length || 0})
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <CommentsSection
                    taskId={task.id}
                    comments={task.comments || []}
                    onCommentAdded={fetchTask}
                  />
                </CardContent>
              </Card>
            </TabsContent>

            {/* IconActivity Tab */}
            <TabsContent value="activity">
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <IconActivity className="h-5 w-5 text-[var(--accent-default)]" />
                    {t('tasks.activity_log')}
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <TaskActivityLog taskId={task.id} maxItems={15} showHeader={false} />
                </CardContent>
              </Card>
            </TabsContent>
          </Tabs>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          <TaskDetailsPanel
            task={task}
            onStatusChange={handleStatusChange}
            variant="sidebar"
            showTimeIndicator={false}
          />
        </div>
      </div>
    </div>
  );
};

export default TaskView;
