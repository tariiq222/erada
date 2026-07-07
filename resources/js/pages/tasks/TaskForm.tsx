import React from 'react';
import { useParams, Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconArrowRight, IconDeviceFloppy, IconSquareCheck, IconClipboardCheck} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Skeleton } from '@shared/ui/Skeleton';
import { PageHeader } from '@shared/ui/PageHeader';
import {
  MilestoneModal,
  UserModal,
  ProjectMilestoneSection,
  TaskDetailsSection,
  StatusScheduleSection,
  TaskTypeSection,
  useTaskForm,
} from './form';

export const TaskForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const preselectedProjectId = searchParams.get('project_id');
  const preselectedMilestoneId = searchParams.get('milestone_id');

  const {
    // Form State
    formData,
    projects,
    milestones,
    parentTasks,
    users,
    departments,
    selectedUser,
    isLoading,
    isSaving,
    errors,
    isEditMode,
    isProjectContext,
    dateConstraints,

    // Form Actions
    handleChange,
    handleSubmit,

    // Milestone Modal
    showMilestoneModal,
    setShowMilestoneModal,
    milestoneFormData,
    milestoneErrors,
    isSavingMilestone,
    openMilestoneModal,
    handleMilestoneChange,
    handleSaveMilestone,

    // User Modal
    showUserModal,
    setShowUserModal,
    userSearchQuery,
    setUserSearchQuery,
    openUserModal,
    handleSelectUser,
  } = useTaskForm({ id, preselectedProjectId, preselectedMilestoneId });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Card>
          <CardContent className="p-6 space-y-4">
            {[...Array(6)].map((_, i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
        <Link to="/tasks" className="hover:text-[var(--accent-default)]">
          {t('tasks.title')}
        </Link>
        <IconArrowRight className="h-4 w-4 rotate-180" />
        <span className="text-[var(--text-primary)]">
          {isEditMode ? t('tasks.edit') : t('tasks.create_new')}
        </span>
      </div>

      {/* Header */}
      <PageHeader
        icon={IconClipboardCheck}
        iconTone="task"
        title={isEditMode ? t('tasks.edit') : t('tasks.create_new')}
        description={isEditMode ? t('tasks.edit_description') : t('tasks.create_description')}
      />

      <form onSubmit={handleSubmit}>
        <Card className="p-0">
          <CardContent className="p-5">
            {/* Section: نوع المهمة - يظهر فقط عندما لا نكون في سياق مشروع */}
            {!isProjectContext && (
              <>
                <TaskTypeSection
                  formData={formData}
                  departments={departments}
                  errors={errors}
                  onChange={handleChange}
                />
                <div className="border-t border-[var(--border-default)] my-5" />
              </>
            )}

            {/* Section: المشروع والمرحلة - يظهر فقط لمهام المشاريع */}
            {(isProjectContext || formData.type === 'project') && (
              <>
                <ProjectMilestoneSection
                  formData={formData}
                  projects={projects}
                  milestones={milestones}
                  parentTasks={parentTasks}
                  selectedUser={selectedUser}
                  errors={errors}
                  onChange={handleChange}
                  onOpenMilestoneModal={openMilestoneModal}
                  onOpenUserModal={openUserModal}
                />
                <div className="border-t border-[var(--border-default)] my-5" />
              </>
            )}

            {/* Section: المسؤول - للمهام غير المشاريع */}
            {!isProjectContext && formData.type !== 'project' && (
              <>
                <div className="flex items-center gap-2 mb-4">
                  <IconSquareCheck className="h-4 w-4 text-[var(--accent-default)]" />
                  <h3 className="text-sm font-semibold text-[var(--text-primary)]">{t('tasks.assignment')}</h3>
                </div>
                <div className="flex flex-wrap items-end gap-4 mb-5">
                  <div className="flex-1 min-w-[200px]">
                    <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.execution_assignee')}</label>
                    <button
                      type="button"
                      onClick={openUserModal}
                      className="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm border border-[var(--border-default)] rounded-lg bg-[var(--surface-base)] hover:border-[var(--accent-default)] hover:bg-[var(--surface-subtle)] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] text-start"
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <div className={`h-7 w-7 rounded-full flex items-center justify-center shrink-0 ${selectedUser ? 'bg-[var(--accent-subtle)]' : 'bg-[var(--surface-muted)]'}`}>
                          <IconSquareCheck className={`h-4 w-4 ${selectedUser ? 'text-[var(--accent-default)]' : 'text-[var(--text-tertiary)]'}`} />
                        </div>
                        <span className={`truncate ${selectedUser ? 'text-[var(--text-primary)]' : 'text-[var(--text-tertiary)]'}`}>
                          {selectedUser?.name || t('tasks.select_assignee')}
                        </span>
                      </div>
                    </button>
                  </div>
                  {/* زر مهمة خاصة - للمهام الشخصية والإدارية */}
                  {(formData.type === 'personal' || formData.type === 'department') && (
                    <div className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => handleChange('is_private', !formData.is_private)}
                        className={`
                          flex items-center gap-2 px-4 py-2 rounded-lg border transition-colors
                          focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]
                          ${formData.is_private
                            ? 'border-[var(--status-warning)] bg-[var(--status-warning-subtle)] text-[var(--status-warning)]'
                            : 'border-[var(--border-default)] hover:border-[var(--border-strong)] text-[var(--text-secondary)]'
                          }
                        `}
                      >
                        <svg className={`h-4 w-4 ${formData.is_private ? 'text-[var(--status-warning)]' : 'text-[var(--text-tertiary)]'}`} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                          <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <span className="text-sm font-medium">{t('tasks.private_task')}</span>
                      </button>
                      {formData.is_private && (
                        <span className="text-xs text-[var(--text-secondary)]">{t('tasks.private_hint')}</span>
                      )}
                    </div>
                  )}
                </div>
                <div className="border-t border-[var(--border-default)] my-5" />
              </>
            )}

            {/* Section: تفاصيل المهمة */}
            <TaskDetailsSection
              formData={formData}
              errors={errors}
              onChange={handleChange}
            />

            {/* Divider */}
            <div className="border-t border-[var(--border-default)] my-5" />

            {/* Section: الحالة والجدول الزمني */}
            <StatusScheduleSection
              formData={formData}
              errors={errors}
              isEditMode={isEditMode}
              dateConstraints={dateConstraints}
              onChange={handleChange}
            />

            {/* Divider */}
            <div className="border-t border-[var(--border-default)] my-5" />

            {/* Actions */}
            <div className="flex items-center justify-end gap-3">
              <Link to={preselectedProjectId ? `/projects/${preselectedProjectId}` : '/tasks'}>
                <Button type="button" variant="outline" size="sm">
                  {t('common.cancel')}
                </Button>
              </Link>
              <Button type="submit" size="sm" loading={isSaving} leftIcon={<IconDeviceFloppy className="h-4 w-4" />}>
                {isEditMode ? t('common.save_changes') : t('tasks.create')}
              </Button>
            </div>
          </CardContent>
        </Card>
      </form>

      {/* Milestone Modal */}
      <MilestoneModal
        isOpen={showMilestoneModal}
        onClose={() => setShowMilestoneModal(false)}
        formData={milestoneFormData}
        errors={milestoneErrors}
        isSaving={isSavingMilestone}
        onChange={handleMilestoneChange}
        onSave={handleSaveMilestone}
      />

      {/* User/Assignee Modal */}
      <UserModal
        isOpen={showUserModal}
        onClose={() => setShowUserModal(false)}
        users={users}
        selectedUserId={formData.assigned_to}
        searchQuery={userSearchQuery}
        onSearchChange={setUserSearchQuery}
        onSelectUser={handleSelectUser}
        onRemoveUser={() => {
          handleChange('assigned_to', '');
          setShowUserModal(false);
        }}
      />
    </div>
  );
};

export default TaskForm;
