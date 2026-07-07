import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { formatDate } from '@shared/lib/utils';
import { useToast } from '@shared/ui/Toast';
import { departmentsApi } from '@entities/hr';
import { projectsApi } from '@entities/project';
import { tasksApi, milestonesApi, unifiedTasksApi } from '@entities/task';
import { usersApi } from '@entities/user';
import type {
  MilestoneOption,
  ProjectOption,
  UserOption,
  TaskOption,
  TaskFormData,
  MilestoneFormData,
  ValidationErrors,
  DateConstraints,
  DepartmentOption,
  TaskType,
} from './types';

interface UseTaskFormProps {
  id?: string;
  preselectedProjectId: string | null;
  preselectedMilestoneId: string | null;
}

export function useTaskForm({ id, preselectedProjectId, preselectedMilestoneId }: UseTaskFormProps) {
  const navigate = useNavigate();
  const { showToast } = useToast();
  const isEditMode = !!id;

  // تحديد نوع المهمة بناءً على السياق
  const defaultTaskType: TaskType = preselectedProjectId ? 'project' : 'project';

  // تاريخ اليوم بتنسيق YYYY-MM-DD
  const getTodayDate = () => {
    const today = new Date();
    return today.toISOString().split('T')[0];
  };

  // Form State
  const [formData, setFormData] = useState<TaskFormData>({
    type: defaultTaskType,
    project_id: preselectedProjectId || '',
    milestone_id: preselectedMilestoneId || '',
    parent_id: '',
    assigned_to: '',
    title: '',
    description: '',
    status: 'todo',
    priority: 'medium',
    start_date: getTodayDate(), // تاريخ البداية الافتراضي هو اليوم
    due_date: '',
    estimated_hours: '',
    // حقول المهام الموحدة
    owner_id: '',
    department_id: '',
    is_private: false,
    recurrence_rule: '',
  });

  const [projects, setProjects] = useState<ProjectOption[]>([]);
  const [milestones, setMilestones] = useState<MilestoneOption[]>([]);
  const [selectedProject, setSelectedProject] = useState<ProjectOption | null>(null);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [parentTasks, setParentTasks] = useState<TaskOption[]>([]);
  const [isLoading, setIsLoading] = useState(isEditMode);
  const [isSaving, setIsSaving] = useState(false);
  const [errors, setErrors] = useState<ValidationErrors>({});

  // هل هذا نموذج سياقي (من المشروع)؟
  const isProjectContext = !!preselectedProjectId;

  // Milestone Modal State
  const [showMilestoneModal, setShowMilestoneModal] = useState(false);
  const [milestoneFormData, setMilestoneFormData] = useState<MilestoneFormData>({
    name: '',
    description: '',
    duration_value: '',
    duration_unit: 'day',
  });
  const [isSavingMilestone, setIsSavingMilestone] = useState(false);
  const [milestoneErrors, setMilestoneErrors] = useState<ValidationErrors>({});

  // User Modal State
  const [showUserModal, setShowUserModal] = useState(false);
  const [userSearchQuery, setUserSearchQuery] = useState('');

  // Fetch data on mount
  useEffect(() => {
    fetchProjects();
    fetchUsers();
    fetchDepartments();
    if (isEditMode && id) {
      fetchTask(id);
    }
  }, [id]);

  // Fetch milestones when project changes
  useEffect(() => {
    if (formData.project_id) {
      fetchProjectDetails(Number(formData.project_id));
      fetchParentTasks(Number(formData.project_id));
    } else {
      setMilestones([]);
      setParentTasks([]);
    }
  }, [formData.project_id]);

  const fetchProjects = async () => {
    try {
      const response = await projectsApi.getAll() as { data: ProjectOption[] };
      setProjects(response.data || []);
    } catch (error) {
      console.warn('Failed to load projects:', error);
    }
  };

  const fetchProjectDetails = async (projectId: number) => {
    try {
      const response = await projectsApi.getOne(projectId) as ProjectOption;
      setSelectedProject(response);
      setMilestones(response.milestones || []);
    } catch {
      setSelectedProject(null);
      setMilestones([]);
    }
  };

  const fetchParentTasks = async (projectId: number) => {
    try {
      const response = await tasksApi.getAll({ project_id: String(projectId) }) as { data: TaskOption[] };
      const tasks = response.data || [];
      setParentTasks(isEditMode && id ? tasks.filter(t => t.id !== Number(id)) : tasks);
    } catch {
      setParentTasks([]);
    }
  };

  const fetchUsers = async () => {
    try {
      const response = await usersApi.getList() as UserOption[];
      setUsers(response);
    } catch (error) {
      console.warn('Failed to load users:', error);
    }
  };

  const fetchDepartments = async () => {
    try {
      const response = await departmentsApi.getList() as DepartmentOption[];
      setDepartments(response);
    } catch (error) {
      console.warn('Failed to load departments:', error);
    }
  };

  const fetchTask = async (taskId: string) => {
    try {
      // نستخدم الـ API حسب السياق
      const rawResponse = isProjectContext
        ? await tasksApi.getOne(Number(taskId)) as any
        : await unifiedTasksApi.getOne(Number(taskId)) as any;
      // Laravel JsonResource يرجع البيانات داخل data wrapper
      const response = rawResponse.data || rawResponse;
      setFormData({
        type: response.type || 'project',
        project_id: response.project_id?.toString() || '',
        milestone_id: response.milestone_id?.toString() || '',
        parent_id: response.parent_id?.toString() || '',
        assigned_to: response.assigned_to?.toString() || '',
        title: response.title || '',
        description: response.description || '',
        status: response.status || 'todo',
        priority: response.priority || 'medium',
        start_date: response.start_date || '',
        due_date: response.due_date || '',
        estimated_hours: response.estimated_hours?.toString() || '',
        owner_id: response.owner_id?.toString() || '',
        department_id: response.department_id?.toString() || '',
        is_private: response.is_private || false,
        recurrence_rule: response.recurrence_rule || '',
      });
    } catch {
      showToast('error', 'فشل في تحميل بيانات المهمة');
      navigate('/tasks');
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (field: keyof TaskFormData, value: string | boolean) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    if (errors[field]) {
      setErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
    // مسح الحقول المرتبطة عند تغيير المشروع
    if (field === 'project_id') {
      setFormData((prev) => ({ ...prev, project_id: value as string, milestone_id: '', parent_id: '' }));
    }
    // مسح الحقول غير المتعلقة بالنوع عند تغيير النوع
    if (field === 'type') {
      const type = value as TaskType;
      setFormData((prev) => ({
        ...prev,
        type,
        // مسح الحقول حسب النوع
        project_id: type === 'project' ? prev.project_id : '',
        milestone_id: type === 'project' ? prev.milestone_id : '',
        parent_id: type === 'project' ? prev.parent_id : '',
        department_id: type === 'department' ? prev.department_id : '',
        owner_id: type === 'personal' ? prev.owner_id : '',
        recurrence_rule: type === 'recurring' ? prev.recurrence_rule : '',
      }));
    }
  };

  const getDateConstraints = (): DateConstraints | null => {
    const selectedMilestone = milestones.find(m => m.id === Number(formData.milestone_id));
    if (selectedMilestone?.start_date && selectedMilestone?.due_date) {
      return {
        minDate: selectedMilestone.start_date,
        maxDate: selectedMilestone.due_date,
        constraintType: 'milestone',
        constraintName: selectedMilestone.name,
      };
    }
    if (selectedProject?.start_date && selectedProject?.end_date) {
      return {
        minDate: selectedProject.start_date,
        maxDate: selectedProject.end_date,
        constraintType: 'project',
        constraintName: selectedProject.name,
      };
    }
    return null;
  };

  const validateDates = (constraints: DateConstraints | null): ValidationErrors => {
    if (!constraints) return {};
    const validationErrors: ValidationErrors = {};

    if (formData.start_date) {
      const startDate = new Date(formData.start_date);
      const minDate = new Date(constraints.minDate);
      const maxDate = new Date(constraints.maxDate);
      if (startDate < minDate || startDate > maxDate) {
        validationErrors.start_date = [`تاريخ البداية يجب أن يكون بين ${formatDate(constraints.minDate)} و ${formatDate(constraints.maxDate)}`];
      }
    }

    if (formData.due_date) {
      const dueDate = new Date(formData.due_date);
      const minDate = new Date(constraints.minDate);
      const maxDate = new Date(constraints.maxDate);
      if (dueDate < minDate || dueDate > maxDate) {
        validationErrors.due_date = [`تاريخ التسليم يجب أن يكون بين ${formatDate(constraints.minDate)} و ${formatDate(constraints.maxDate)}`];
      }
    }

    if (formData.start_date && formData.due_date) {
      if (new Date(formData.due_date) < new Date(formData.start_date)) {
        validationErrors.due_date = ['تاريخ التسليم يجب أن يكون بعد تاريخ البداية'];
      }
    }

    return validationErrors;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    // التحقق من التواريخ فقط لمهام المشاريع
    if (formData.type === 'project') {
      const constraints = getDateConstraints();
      const validationErrors = validateDates(constraints);
      if (Object.keys(validationErrors).length > 0) {
        setErrors(validationErrors);
        showToast('error', 'يرجى تصحيح أخطاء التواريخ');
        return;
      }
    }

    setIsSaving(true);
    try {
      // بناء البيانات حسب نوع المهمة
      if (isProjectContext || formData.type === 'project') {
        // مهمة مشروع - استخدم tasksApi
        const submitData = {
          project_id: formData.project_id ? Number(formData.project_id) : null,
          milestone_id: formData.milestone_id ? Number(formData.milestone_id) : null,
          parent_id: formData.parent_id ? Number(formData.parent_id) : null,
          assigned_to: formData.assigned_to ? Number(formData.assigned_to) : null,
          title: formData.title,
          description: formData.description || null,
          status: formData.status,
          priority: formData.priority,
          start_date: formData.start_date || null,
          due_date: formData.due_date || null,
          estimated_hours: formData.estimated_hours ? Number(formData.estimated_hours) : null,
        };

        if (isEditMode && id) {
          await tasksApi.update(Number(id), submitData);
          showToast('success', 'تم تحديث المهمة بنجاح');
        } else {
          await tasksApi.create(submitData);
          showToast('success', 'تم إنشاء المهمة بنجاح');
        }
      } else {
        // مهمة موحدة (شخصية/إدارية/متكررة) - استخدم unifiedTasksApi
        const submitData = {
          type: formData.type,
          title: formData.title,
          description: formData.description || null,
          status: formData.status,
          priority: formData.priority,
          start_date: formData.start_date || null,
          due_date: formData.due_date || null,
          estimated_hours: formData.estimated_hours ? Number(formData.estimated_hours) : null,
          assigned_to: formData.assigned_to ? Number(formData.assigned_to) : null,
          // حقول خاصة بنوع المهمة
          owner_id: formData.type === 'personal' && formData.owner_id ? Number(formData.owner_id) : null,
          department_id: formData.type === 'department' && formData.department_id ? Number(formData.department_id) : null,
          is_private: formData.is_private || false,
          recurrence_rule: formData.type === 'recurring' && formData.recurrence_rule ? formData.recurrence_rule : null,
        };

        if (isEditMode && id) {
          await unifiedTasksApi.update(Number(id), submitData);
          showToast('success', 'تم تحديث المهمة بنجاح');
        } else {
          await unifiedTasksApi.create(submitData);
          showToast('success', 'تم إنشاء المهمة بنجاح');
        }
      }

      navigate(preselectedProjectId ? `/projects/${preselectedProjectId}` : '/tasks');
    } catch (error: any) {
      if (error.errors) {
        setErrors(error.errors);
      } else {
        showToast('error', error.message || 'حدث خطأ أثناء الحفظ');
      }
    } finally {
      setIsSaving(false);
    }
  };

  // Milestone Modal Handlers
  const openMilestoneModal = () => {
    setMilestoneFormData({ name: '', description: '', duration_value: '', duration_unit: 'day' });
    setMilestoneErrors({});
    setShowMilestoneModal(true);
  };

  const handleMilestoneChange = (field: keyof MilestoneFormData, value: string) => {
    setMilestoneFormData((prev) => ({ ...prev, [field]: value }));
    if (milestoneErrors[field]) {
      setMilestoneErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handleSaveMilestone = async () => {
    if (!formData.project_id) {
      showToast('error', 'يجب اختيار المشروع أولاً');
      return;
    }
    if (!milestoneFormData.duration_value || parseInt(milestoneFormData.duration_value) <= 0) {
      setMilestoneErrors({ duration_value: ['يجب تحديد مدة المرحلة'] });
      return;
    }

    setMilestoneErrors({});
    setIsSavingMilestone(true);
    try {
      const response = await milestonesApi.create({
        project_id: Number(formData.project_id),
        name: milestoneFormData.name,
        description: milestoneFormData.description || null,
        duration_value: parseInt(milestoneFormData.duration_value),
        duration_unit: milestoneFormData.duration_unit,
      }) as { milestone: MilestoneOption };

      setMilestones((prev) => [...prev, response.milestone]);
      setFormData((prev) => ({ ...prev, milestone_id: response.milestone.id.toString() }));
      showToast('success', 'تم إنشاء المرحلة بنجاح');
      setShowMilestoneModal(false);
    } catch (error: any) {
      if (error.errors) {
        setMilestoneErrors(error.errors);
      } else {
        showToast('error', error.message || 'حدث خطأ أثناء إنشاء المرحلة');
      }
    } finally {
      setIsSavingMilestone(false);
    }
  };

  // User Modal Handlers
  const openUserModal = () => {
    setUserSearchQuery('');
    setShowUserModal(true);
  };

  const handleSelectUser = (userId: number) => {
    handleChange('assigned_to', userId.toString());
    setShowUserModal(false);
  };

  const selectedUser = users?.find(u => u.id === Number(formData.assigned_to));
  const dateConstraints = getDateConstraints();

  const selectedOwner = users?.find(u => u.id === Number(formData.owner_id));
  const selectedDepartment = departments?.find(d => d.id === Number(formData.department_id));

  return {
    // Form State
    formData,
    projects,
    milestones,
    parentTasks,
    users,
    departments,
    selectedUser,
    selectedOwner,
    selectedDepartment,
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
  };
}
