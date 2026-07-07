import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Skeleton,
  SkeletonText,
  Modal,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  PageHeader,
} from '@shared/ui';
import {
  TaskActivityLog,
  TaskDetailsPanel,
} from '@widgets/task';
import {IconEdit, IconX, IconLayoutKanban, IconMessage, IconPaperclip, IconInfoCircle, IconListTree, IconExternalLink, IconActivity, IconAlertTriangle, IconClipboardCheck} from '@tabler/icons-react';
import {
  CommentsSection,
  DetailsTab,
  SubtasksTab,
  AttachmentsTab,
  useTaskViewModal,
} from './components';

interface TaskViewModalProps {
  taskId: number | null;
  isOpen: boolean;
  onClose: () => void;
  onTaskUpdated?: () => void;
}

const TaskViewModal: React.FC<TaskViewModalProps> = ({
  taskId,
  isOpen,
  onClose,
  onTaskUpdated,
}) => {
  const { t } = useTranslation();

  const {
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
  } = useTaskViewModal({ taskId, isOpen, onTaskUpdated });

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      size="full"
    >
      {loading ? (
        <div className="p-6 space-y-4">
          <Skeleton width={300} height={28} />
          <Skeleton width={200} height={20} />
          <div className="space-y-3 mt-6">
            <SkeletonText lines={4} />
          </div>
        </div>
      ) : error || !task ? (
        <div className="text-center py-12">
          <IconAlertTriangle className="h-12 w-12 text-[var(--status-danger)] mx-auto mb-4" />
          <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">
            {error || t('tasks.not_found')}
          </h3>
          <Button onClick={onClose}>
            {t('common.close')}
          </Button>
        </div>
      ) : (
        <div className="flex flex-col h-full max-h-[90vh]">
          {/* Header */}
          <div className="px-6 py-4 border-b border-[var(--border-default)] shrink-0 bg-[var(--surface-subtle)]">
            <PageHeader
              icon={IconClipboardCheck}
              iconTone="task"
              size="compact"
              title={task.title}
              status={
                <div className="flex flex-wrap items-center gap-2">
                  {task.project && (
                    <Link
                      to={`/projects/${task.project.id}`}
                      onClick={onClose}
                      className="inline-flex items-center gap-1 px-3 py-1 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded-lg text-xs font-medium transition-colors"
                    >
                      <IconLayoutKanban className="h-3.5 w-3.5" />
                      {task.project.code} - {task.project.name}
                    </Link>
                  )}
                  {isOverdue && (
                    <div className="inline-flex items-center gap-1 px-2 py-1 bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)] rounded-lg text-xs font-medium">
                      <IconAlertTriangle className="h-3.5 w-3.5" />
                      {t('tasks.overdue')}
                    </div>
                  )}
                </div>
              }
              actions={
                <>
                  <Link
                    to={`/tasks/${task.id}`}
                    onClick={onClose}
                    className="p-2 rounded-lg text-[var(--text-secondary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
                    title={t('tasks.open_full_page')}
                    aria-label={t('tasks.open_full_page')}
                  >
                    <IconExternalLink className="h-5 w-5" />
                  </Link>
                  <Link to={`/tasks/${task.id}/edit`} onClick={onClose}>
                    <Button leftIcon={<IconEdit className="h-4 w-4" />} variant="outline" size="sm">
                      {t('common.edit')}
                    </Button>
                  </Link>
                  <button
                    onClick={onClose}
                    aria-label={t('common.close')}
                    className="p-2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
                  >
                    <IconX className="h-5 w-5" />
                  </button>
                </>
              }
            />
          </div>

          {/* Content - Two Column Layout */}
          <div className="flex-1 overflow-hidden flex">
            {/* Main Content */}
            <div className="flex-1 overflow-y-auto">
              <Tabs defaultValue="details" value={activeTab} onValueChange={setActiveTab}>
                <div className="px-6 border-b border-[var(--border-default)] bg-[var(--surface-base)]">
                  <TabsList>
                    <TabsTrigger value="details" icon={<IconInfoCircle className="h-4 w-4" />}>
                      {t('common.details')}
                    </TabsTrigger>
                    {/* إخفاء تاب المهام الفرعية إذا كانت المهمة نفسها فرعية */}
                    {!task.parent && (
                      <TabsTrigger value="subtasks" icon={<IconListTree className="h-4 w-4" />}>
                        {t('tasks.subtasks')} ({task.subtasks?.length || 0})
                      </TabsTrigger>
                    )}
                    <TabsTrigger value="comments" icon={<IconMessage className="h-4 w-4" />}>
                      {t('tasks.comments')} ({task.comments?.length || 0})
                    </TabsTrigger>
                    <TabsTrigger value="attachments" icon={<IconPaperclip className="h-4 w-4" />}>
                      {t('tasks.attachments')} ({task.comments?.reduce((acc, c) => acc + (c.attachments?.length || 0), 0) || 0})
                    </TabsTrigger>
                    <TabsTrigger value="activity" icon={<IconActivity className="h-4 w-4" />}>
                      {t('tasks.log')}
                    </TabsTrigger>
                  </TabsList>
                </div>

                <div className="p-6">
                  {/* Details Tab */}
                  <TabsContent value="details" className="mt-0">
                    <DetailsTab task={task} isOverdue={Boolean(isOverdue)} />
                  </TabsContent>

                  {/* Subtasks Tab */}
                  <TabsContent value="subtasks" className="mt-0">
                    <SubtasksTab
                      subtasks={task.subtasks}
                      users={subtaskUsers}
                      showForm={showSubtaskForm}
                      subtaskTitle={subtaskTitle}
                      subtaskAssignee={subtaskAssignee}
                      isAdding={isAddingSubtask}
                      onShowForm={() => setShowSubtaskForm(true)}
                      onHideForm={() => setShowSubtaskForm(false)}
                      onTitleChange={setSubtaskTitle}
                      onAssigneeChange={setSubtaskAssignee}
                      onAdd={handleAddSubtask}
                      onStatusChange={handleSubtaskStatusChange}
                      onUpdate={handleSubtaskUpdate}
                      onDelete={handleSubtaskDelete}
                    />
                  </TabsContent>

                  {/* Comments Tab */}
                  <TabsContent value="comments" className="mt-0">
                    <CommentsSection
                      taskId={task.id}
                      initialComments={task.comments || []}
                    />
                  </TabsContent>

                  {/* IconActivity Tab */}
                  <TabsContent value="activity" className="mt-0">
                    <TaskActivityLog taskId={task.id} maxItems={10} showHeader={false} />
                  </TabsContent>

                  {/* Attachments Tab */}
                  <TabsContent value="attachments" className="mt-0">
                    <AttachmentsTab comments={task.comments || []} />
                  </TabsContent>
                </div>
              </Tabs>
            </div>

            {/* Sidebar */}
            <div className="hidden lg:block w-80 border-r border-[var(--border-default)] bg-[var(--surface-subtle)] overflow-y-auto">
              <div className="p-4">
                <TaskDetailsPanel
                  task={task}
                  onStatusChange={handleStatusChange}
                  onClose={onClose}
                  variant="sidebar"
                  showTimeIndicator={true}
                />
              </div>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
};

export default TaskViewModal;
