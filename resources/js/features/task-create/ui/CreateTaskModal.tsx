import React, { useState, useEffect } from 'react';
import {IconDeviceFloppy, IconSquareCheck} from '@tabler/icons-react';
import { Modal, ModalHeader, ModalBody, ModalFooter } from '@shared/ui/Modal';
import { Button } from '@shared/ui/Button';
import { useToast } from '@shared/ui/Toast';
import { useAuth } from '@shared/contexts/AuthContext';
import { tasksApi } from '@entities/task';
import { usersApi } from '@entities/user';
import { recommendationsApi } from '@features/meetings/api';
import { formatDate } from '@shared/lib/utils';
import TaskFormFields from './TaskFormFields';
import UserSelectModal from './UserSelectModal';
import {
  CreateTaskModalProps,
  TaskFormData,
  MilestoneOption,
  UserOption,
  TaskOption,
  RecommendationOption,
  ValidationErrors,
  initialFormData,
} from './types';

const CreateTaskModal: React.FC<CreateTaskModalProps> = ({
  isOpen,
  onClose,
  projectId,
  project,
  onTaskCreated,
  meetingId,
}) => {
  const { showToast } = useToast();
  const { user } = useAuth();

  const [formData, setFormData] = useState<TaskFormData>(initialFormData);
  const [milestones, setMilestones] = useState<MilestoneOption[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [parentTasks, setParentTasks] = useState<TaskOption[]>([]);
  const [recommendations, setRecommendations] = useState<RecommendationOption[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  const [errors, setErrors] = useState<ValidationErrors>({});
  const [showUserModal, setShowUserModal] = useState(false);

  useEffect(() => {
    if (isOpen) {
      // تعيين المستخدم الحالي كمسؤول افتراضي
      setFormData({
        ...initialFormData,
        assigned_to: user?.id ? String(user.id) : '',
      });
      setErrors({});
      setMilestones(project.milestones || []);
      fetchUsers();
      fetchParentTasks();
      if (meetingId) {
        fetchRecommendations(meetingId);
      } else {
        setRecommendations([]);
      }
    }
  }, [isOpen, project, user?.id, meetingId]);

  const fetchUsers = async () => {
    try {
      const response = await usersApi.getList() as UserOption[];
      setUsers(response);
    } catch (error) {
      console.warn('Failed to load users:', error);
    }
  };

  const fetchParentTasks = async () => {
    try {
      const response = await tasksApi.getAll({ project_id: String(projectId) }) as { data: TaskOption[] };
      setParentTasks(response.data || []);
    } catch {
      setParentTasks([]);
    }
  };

  const fetchRecommendations = async (mid: number) => {
    try {
      const res = (await recommendationsApi.getAll({
        meeting_id: String(mid),
        per_page: '50',
      })) as { data?: RecommendationOption[] } | RecommendationOption[];
      const list = Array.isArray(res) ? res : res.data ?? [];
      // Only open / non-completed recommendations make sense as task sources.
      setRecommendations(
        list.filter(
          (r) => !r.status || (r.status !== 'completed' && r.status !== 'rejected'),
        ),
      );
    } catch {
      setRecommendations([]);
    }
  };

  const handleChange = (field: keyof TaskFormData, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    if (errors[field]) {
      setErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const getDateConstraints = () => {
    const selectedMilestone = milestones.find(m => m.id === Number(formData.milestone_id));

    if (selectedMilestone?.start_date && selectedMilestone?.due_date) {
      return {
        minDate: selectedMilestone.start_date,
        maxDate: selectedMilestone.due_date,
        constraintType: 'milestone' as const,
        constraintName: selectedMilestone.name,
      };
    }

    if (project?.start_date && project?.end_date) {
      return {
        minDate: project.start_date,
        maxDate: project.end_date,
        constraintType: 'project' as const,
        constraintName: project.name,
      };
    }

    return null;
  };

  const dateConstraints = getDateConstraints();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (dateConstraints) {
      const validationErrors: ValidationErrors = {};

      if (formData.start_date) {
        const startDate = new Date(formData.start_date);
        const minDate = new Date(dateConstraints.minDate);
        const maxDate = new Date(dateConstraints.maxDate);

        if (startDate < minDate || startDate > maxDate) {
          validationErrors.start_date = [`تاريخ البداية يجب أن يكون بين ${formatDate(dateConstraints.minDate)} و ${formatDate(dateConstraints.maxDate)}`];
        }
      }

      if (formData.due_date) {
        const dueDate = new Date(formData.due_date);
        const minDate = new Date(dateConstraints.minDate);
        const maxDate = new Date(dateConstraints.maxDate);

        if (dueDate < minDate || dueDate > maxDate) {
          validationErrors.due_date = [`تاريخ التسليم يجب أن يكون بين ${formatDate(dateConstraints.minDate)} و ${formatDate(dateConstraints.maxDate)}`];
        }
      }

      if (formData.start_date && formData.due_date) {
        const startDate = new Date(formData.start_date);
        const dueDate = new Date(formData.due_date);
        if (dueDate < startDate) {
          validationErrors.due_date = ['تاريخ التسليم يجب أن يكون بعد تاريخ البداية'];
        }
      }

      if (Object.keys(validationErrors).length > 0) {
        setErrors(validationErrors);
        showToast('error', 'يرجى تصحيح أخطاء التواريخ');
        return;
      }
    }

    setIsSaving(true);

    try {
      const submitData: any = {
        project_id: projectId,
        milestone_id: formData.milestone_id ? Number(formData.milestone_id) : null,
        parent_id: formData.parent_id ? Number(formData.parent_id) : null,
        assigned_to: formData.assigned_to ? Number(formData.assigned_to) : null,
        title: formData.title,
        description: formData.description || null,
        priority: formData.priority,
        start_date: formData.start_date || null,
        due_date: formData.due_date || null,
        estimated_hours: formData.estimated_hours ? Number(formData.estimated_hours) : null,
        source_type: formData.source_type || null,
        source_id: formData.source_id ? Number(formData.source_id) : null,
      };

      await tasksApi.create(submitData);
      showToast('success', 'تم إنشاء المهمة بنجاح');
      onTaskCreated();
      onClose();
    } catch (error: any) {
      if (error.errors) {
        setErrors(error.errors);
        showToast('error', error.message || 'يرجى تصحيح الأخطاء في النموذج');
      } else {
        showToast('error', error.message || 'حدث خطأ أثناء الحفظ');
      }
    } finally {
      setIsSaving(false);
    }
  };

  const handleSelectUser = (userId: number) => {
    handleChange('assigned_to', userId.toString());
  };

  const handleRemoveUser = () => {
    handleChange('assigned_to', '');
  };

  return (
    <>
      <Modal open={isOpen} onClose={onClose} size="lg">
        <ModalHeader onClose={onClose}>
          <div className="flex items-center gap-3">
            <div className="h-10 w-10 rounded-xl bg-[var(--accent-default)] flex items-center justify-center">
              <IconSquareCheck className="h-5 w-5 text-white" />
            </div>
            <div>
              <h2 className="text-lg font-bold text-[var(--text-primary)]">إضافة مهمة جديدة</h2>
              <p className="text-sm text-[var(--text-tertiary)]">{project.code} - {project.name}</p>
            </div>
          </div>
        </ModalHeader>

        <form onSubmit={handleSubmit}>
          <ModalBody>
            <TaskFormFields
              formData={formData}
              onChange={handleChange}
              errors={errors}
              milestones={milestones}
              parentTasks={parentTasks}
              users={users}
              dateConstraints={dateConstraints}
              onOpenUserModal={() => setShowUserModal(true)}
              recommendations={meetingId ? recommendations : undefined}
            />
          </ModalBody>

          <ModalFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              إلغاء
            </Button>
            <Button type="submit" loading={isSaving} leftIcon={<IconDeviceFloppy className="h-4 w-4" />}>
              إنشاء المهمة
            </Button>
          </ModalFooter>
        </form>
      </Modal>

      <UserSelectModal
        isOpen={showUserModal}
        onClose={() => setShowUserModal(false)}
        users={users}
        selectedUserId={formData.assigned_to}
        onSelectUser={handleSelectUser}
        onRemoveUser={handleRemoveUser}
      />
    </>
  );
};

export default CreateTaskModal;
